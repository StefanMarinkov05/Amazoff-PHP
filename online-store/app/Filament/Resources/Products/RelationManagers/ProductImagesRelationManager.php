<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\RelationManagers;

use App\Actions\Catalogue\AddProductImage;
use App\Actions\Catalogue\RemoveProductImage;
use App\Actions\Catalogue\SetMainProductImage;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ProductImagesRelationManager extends RelationManager
{
    use ReportsDomainFailures;

    protected static string $relationship = 'productImages';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('path')
                    ->label('Image')
                    ->image()
                    ->disk(ProductImage::DISK)
                    ->directory(ProductImage::DIRECTORY)
                    ->maxSize(4096)
                    ->required(),
                TextInput::make('alt_text')
                    ->maxLength(255)
                    ->nullable(),
                TextInput::make('sort_order')
                    ->required()
                    ->integer()
                    ->default(0),
                // No is_main toggle. "Exactly one main image" is an invariant
                // across the set, so promotion goes through the Make main
                // action and SetMainProductImage — a toggle per row is a
                // second door onto the same rule.
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('path')
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('path')
                    ->disk(ProductImage::DISK)
                    ->label('Image'),
                TextColumn::make('alt_text')
                    ->searchable(),
                IconColumn::make('is_main')
                    ->label('Main')
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->numeric()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data): ProductImage => $this->reportingDomainFailures(
                        function () use ($data): ProductImage {
                            /** @var Product $product */
                            $product = $this->getOwnerRecord();

                            return app(AddProductImage::class)->handle($product, $data, $this->actor());
                        },
                        'Image could not be added',
                    )),
            ])
            ->recordActions([
                // Editing touches alt_text and sort_order only — one table, no
                // invariant, so ADR-0007 leaves it as default CRUD.
                EditAction::make(),
                Action::make('setMain')
                    ->label('Make main')
                    ->icon(Heroicon::OutlinedStar)
                    ->visible(fn (ProductImage $record): bool => ! $record->is_main)
                    ->requiresConfirmation()
                    ->action(fn (ProductImage $record) => $this->reportingDomainFailures(
                        fn () => app(SetMainProductImage::class)->handle($record, $this->actor()),
                        'Main image could not be changed',
                    )),
                DeleteAction::make()
                    ->using(fn (Model $record): bool => $this->reportingDomainFailures(
                        function () use ($record): bool {
                            /** @var ProductImage $record */
                            app(RemoveProductImage::class)->handle($record, $this->actor());

                            return true;
                        },
                        'Image could not be removed',
                    )),
            ]);
        // No delete bulk action: it writes Eloquent directly, so it would skip
        // both the in-use check and the successor promotion.
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
