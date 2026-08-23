<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\RelationManagers;

use App\Actions\Catalogue\AddProductVariation;
use App\Actions\Catalogue\ForceDeleteProductVariation;
use App\Actions\Catalogue\RemoveProductVariation;
use App\Actions\Catalogue\SetVariationImages;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Arr;

class ProductVariationsRelationManager extends RelationManager
{
    use ReportsDomainFailures;

    protected static string $relationship = 'productVariations';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('sku')
                    ->label('SKU')
                    ->required()
                    ->maxLength(64)
                    ->unique(ignoreRecord: true),
                TextInput::make('price')
                    ->numeric()
                    ->step('0.01')
                    ->rules(['decimal:0,2', 'max:99999999.99'])
                    ->prefix('EUR')
                    ->nullable(),
                TextInput::make('discount_price')
                    ->numeric()
                    ->step('0.01')
                    ->rules(['decimal:0,2', 'max:99999999.99'])
                    ->prefix('EUR'),
                TextInput::make('weight')
                    ->numeric()
                    ->step('0.01')
                    ->rules(['decimal:0,2', 'max:999999.99'])
                    ->nullable(),
                // Not a column, and create-only. AddProductVariation turns it
                // into an InitialStock movement; later corrections are their
                // own movement types.
                TextInput::make('initial_quantity')
                    ->label('Opening stock')
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->required()
                    ->visibleOn(Operation::Create),
                Toggle::make('is_available')
                    ->required()
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sku')
            ->columns([
                TextColumn::make('images_count')
                    ->label('Images')
                    ->counts('images')
                    ->badge(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('price')
                    ->money()
                    ->sortable(),
                TextColumn::make('discount_price')
                    ->money()
                    ->sortable(),
                TextColumn::make('weight')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_available')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->headerActions([
                // A variation and its stock row are 2 tables and one
                // invariant. Filament's default create wrote the variation
                // alone, and every inventory Action reads the missing row with
                // firstOrFail().
                CreateAction::make()
                    ->using(fn (array $data): ProductVariation => $this->reportingDomainFailures(
                        function () use ($data): ProductVariation {
                            /** @var Product $product */
                            $product = $this->getOwnerRecord();

                            return app(AddProductVariation::class)->handle(
                                $product,
                                Arr::except($data, ['initial_quantity']),
                                (int) ($data['initial_quantity'] ?? 0),
                                $this->actor(),
                            );
                        },
                        'Variation could not be added',
                    )),
            ])
            ->recordActions([
                EditAction::make(),
                // Filament relation managers cannot nest, so the gallery is a
                // row action and modal rather than a relation manager of its
                // own — which happens to fit SetVariationImages anyway: the
                // modal submits the whole set in one call.
                Action::make('manageImages')
                    ->label('Images')
                    ->icon(Heroicon::OutlinedPhoto)
                    ->modalSubmitActionLabel('Save gallery')
                    // Row order is the whole point: fillForm must hand the
                    // Repeater the gallery in position order, or "save with no
                    // changes" would silently rewrite position to array order
                    // on the very first open.
                    ->fillForm(fn (ProductVariation $record): array => [
                        'images' => $record->images()
                            ->pluck('product_images.id')
                            ->map(fn (int $id): array => ['image_id' => $id])
                            ->all(),
                    ])
                    ->schema([
                        Repeater::make('images')
                            ->label('Gallery')
                            ->hiddenLabel()
                            ->reorderableWithDragAndDrop()
                            ->addActionLabel('Add image')
                            ->defaultItems(0)
                            ->schema([
                                ViewField::make('thumbnail')
                                    ->view('filament.forms.components.variation-image-thumbnail')
                                    // Redraws when the row's own image_id
                                    // changes, not just on open — otherwise
                                    // picking a different photo would leave
                                    // the old one showing until save.
                                    ->live(),
                                Select::make('image_id')
                                    ->label('Image')
                                    ->hiddenLabel()
                                    ->live()
                                    ->searchable()
                                    ->required()
                                    // Scoped to the variation's own product.
                                    // The Action refuses anything else with
                                    // ImageNotOnProductException; this keeps
                                    // an administrator from having to
                                    // discover that.
                                    ->options(function (ProductVariation $record): array {
                                        /** @var Product $product */
                                        $product = $record->product;

                                        return $product->productImages()
                                            ->orderBy('sort_order')
                                            ->orderBy('id')
                                            ->pluck('path', 'id')
                                            ->all();
                                    }),
                            ])
                            ->columns(2)
                            ->columnSpanFull()
                            ->helperText('Drag to reorder. Row order becomes display order. Leave empty to inherit the main product image.'),
                    ])
                    ->action(fn (ProductVariation $record, array $data) => $this->reportingDomainFailures(
                        fn () => app(SetVariationImages::class)->handle(
                            $record,
                            array_column($data['images'] ?? [], 'image_id'),
                            $this->actor(),
                        ),
                        'Gallery could not be saved',
                    )),
                DeleteAction::make()
                    ->using(fn (Model $record): bool => $this->reportingDomainFailures(
                        function () use ($record): bool {
                            /** @var ProductVariation $record */
                            app(RemoveProductVariation::class)->handle($record, $this->actor());

                            return true;
                        },
                        'Variation could not be removed',
                    )),
                ForceDeleteAction::make()
                    ->using(fn (Model $record): bool => $this->reportingDomainFailures(
                        function () use ($record): bool {
                            /** @var ProductVariation $record */
                            app(ForceDeleteProductVariation::class)->handle($record, $this->actor());

                            return true;
                        },
                        'Variation could not be permanently deleted',
                    )),
                RestoreAction::make(),
            ])
            // No delete or force-delete bulk action: both write Eloquent
            // directly, which is the bypass this class exists to close.
            // Restoring cannot break the invariant, so it stays.
            ->toolbarActions([
                BulkActionGroup::make([
                    RestoreBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withoutGlobalScopes([
                    SoftDeletingScope::class,
                ]));
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
