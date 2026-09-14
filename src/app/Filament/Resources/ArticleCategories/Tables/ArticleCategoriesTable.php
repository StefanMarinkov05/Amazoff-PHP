<?php

declare(strict_types=1);

namespace App\Filament\Resources\ArticleCategories\Tables;

use App\Actions\Content\DeleteArticleCategory;
use App\Filament\Actions\DomainDeleteBulkAction;
use App\Models\ArticleCategory;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ArticleCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
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
                        fn (ArticleCategory $record, ?User $actor) => app(DeleteArticleCategory::class)->handle($record, $actor),
                        'article category',
                    ),
                    DomainDeleteBulkAction::makeAtomic(
                        fn (ArticleCategory $record, ?User $actor) => app(DeleteArticleCategory::class)->handle($record, $actor),
                        'article category',
                    ),
                ]),
            ]);
    }
}
