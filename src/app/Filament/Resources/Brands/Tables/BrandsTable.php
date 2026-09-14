<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Tables;

use App\Actions\Catalogue\DeleteBrand;
use App\Filament\Actions\DomainDeleteBulkAction;
use App\Models\Brand;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Routes each row through DeleteBrand so an in-use brand is
                    // refused with a message naming the blocking products,
                    // matching EditBrand's single-delete path. The bulk path is
                    // a second call site Filament wires up by default.
                    DomainDeleteBulkAction::make(
                        fn (Brand $record, ?User $actor) => app(DeleteBrand::class)->handle($record, $actor),
                        'brand',
                    ),
                    DomainDeleteBulkAction::makeAtomic(
                        fn (Brand $record, ?User $actor) => app(DeleteBrand::class)->handle($record, $actor),
                        'brand',
                    ),
                ]),
            ]);
    }
}
