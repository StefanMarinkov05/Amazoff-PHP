<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductCategories\Tables;

use App\Actions\Catalogue\DeleteProductCategory;
use App\Filament\Actions\DomainDeleteBulkAction;
use App\Models\ProductCategory;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // parent.name below crosses a relation; see CLAUDE.md's N+1 rule.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('parent'))
            ->columns([
                TextColumn::make('parent.name')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('description')
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
                    DomainDeleteBulkAction::make(
                        fn (ProductCategory $record, ?User $actor) => app(DeleteProductCategory::class)->handle($record, $actor),
                        'category',
                    ),
                    DomainDeleteBulkAction::makeAtomic(
                        fn (ProductCategory $record, ?User $actor) => app(DeleteProductCategory::class)->handle($record, $actor),
                        'category',
                    ),
                ]),
            ]);
    }
}
