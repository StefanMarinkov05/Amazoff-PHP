<?php

declare(strict_types=1);

namespace App\Filament\Resources\Articles\Tables;

use App\Actions\Content\PublishArticle;
use App\Enums\ArticleStatus;
use App\Models\Article;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ArticlesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('main_image_path')
                    ->label('Image'),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('articleCategory.name')
                    ->label('Category')
                    ->placeholder('Uncategorised')
                    ->searchable(),
                TextColumn::make('author.email')
                    ->label('Author')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                IconColumn::make('featured')
                    ->boolean(),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->placeholder('Never published')
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(ArticleStatus::class)
                    ->multiple(),
                SelectFilter::make('articleCategory')
                    ->relationship('articleCategory', 'name')
                    ->label('Category'),
                TernaryFilter::make('featured'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::statusActions(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * One button per status, generated from the enum rather than hand-written.
     *
     * `visible()` asks the same `canTransitionTo()` the Action enforces, so the
     * menu only ever offers legal moves and the matrix stays the single place
     * the rule lives — widening ArticleStatus widens this menu automatically.
     * The Action still re-checks: a button being hidden is not a guarantee, a
     * thrown exception is.
     */
    private static function statusActions()
    {
        return ActionGroup::make(
            array_map(
                fn (ArticleStatus $target) => Action::make("moveTo{$target->value}")
                    ->label($target->getLabel())
                    ->color($target->getColor())
                    ->authorize('publish')
                    ->visible(fn (Article $record): bool => $record->status->canTransitionTo($target))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Article $record) => "Move \"{$record->title}\" to {$target->getLabel()}?")
                    ->action(fn (Article $record, PublishArticle $publishArticle) => $publishArticle->handle($record, $target, auth()->user())),
                ArticleStatus::cases(),
            ),
        )
            ->label('Change status')
            ->icon(Heroicon::OutlinedArrowPath)
            ->button()
            ->outlined();
    }
}
