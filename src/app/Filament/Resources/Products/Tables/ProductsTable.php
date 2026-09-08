<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Actions\Catalogue\DeleteProduct;
use App\Filament\Actions\DomainDeleteBulkAction;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // productCategory/brand columns below cross a relation each; see
            // CLAUDE.md's N+1 rule and explanation/filament-resources.md.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['productCategory', 'brand']))
            ->columns([
                // Kept default-visible: identity, price, and the two states
                // an admin scanning the list actually decides against. SKU
                // details, SEO copy, dimensions, and timestamps moved to
                // toggleable-hidden — 19 always-on columns previously, none
                // of which lost reachability, all of which are still on
                // ViewProduct in full.
                TextColumn::make('productCategory.name')
                    ->label('Category')
                    ->searchable(),
                TextColumn::make('brand.name')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(fn (Product $record): string => $record->name),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('regular_price')
                    ->money()
                    ->sortable(),
                TextColumn::make('discount_price')
                    ->money()
                    ->sortable(),
                IconColumn::make('is_available')
                    ->boolean(),
                IconColumn::make('is_featured')
                    ->boolean(),
                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('short_description')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(fn (Product $record): ?string => $record->short_description)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('discount_starts_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('discount_ends_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vat_rate')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('min_order_quantity')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('weight_g')
                    ->label('Weight')
                    ->suffix(' g')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('length_mm')
                    ->label('L')
                    ->suffix(' mm')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('width_mm')
                    ->label('W')
                    ->suffix(' mm')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('height_mm')
                    ->label('H')
                    ->suffix(' mm')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('seo_title')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(fn (Product $record): ?string => $record->seo_title)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('seo_description')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(fn (Product $record): ?string => $record->seo_description)
                    ->toggleable(isToggledHiddenByDefault: true),
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
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Routes each row through DeleteProduct so its soft-delete
                    // cascade to variations still runs — Product soft-deletes,
                    // so the default bulk delete succeeds silently while
                    // leaving variations reservable. ForceDelete/Restore keep
                    // Filament's defaults: neither can break an invariant.
                    DomainDeleteBulkAction::make(
                        fn (Product $record, ?User $actor) => app(DeleteProduct::class)->handle($record, $actor),
                        'product',
                    ),
                    DomainDeleteBulkAction::makeAtomic(
                        fn (Product $record, ?User $actor) => app(DeleteProduct::class)->handle($record, $actor),
                        'product',
                    ),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
