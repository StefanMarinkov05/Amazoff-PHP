<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductReviews\Tables;

use App\Actions\ProductReview\ApproveProductReview;
use App\Actions\ProductReview\UnapproveProductReview;
use App\Models\ProductReview;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProductReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // product/user/orderItem columns below each cross a relation;
            // see CLAUDE.md's N+1 rule.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['product', 'user', 'orderItem']))
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable(),
                TextColumn::make('author_name')
                    ->searchable(),
                TextColumn::make('user.email')
                    ->label('Account')
                    ->placeholder('Deleted account')
                    ->searchable(),
                TextColumn::make('orderItem.product_sku')
                    ->label('Verified purchase')
                    ->placeholder('No linked order'),
                TextColumn::make('rating')
                    ->formatStateUsing(fn (int $state): string => str_repeat('★', $state).str_repeat('☆', 5 - $state))
                    ->sortable(),
                TextColumn::make('body')
                    ->limit(60)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('approved')
                    ->boolean(),
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
                TernaryFilter::make('approved')
                    ->trueLabel('Approved')
                    ->falseLabel('Pending'),
                SelectFilter::make('product')
                    ->relationship('product', 'name')
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')
                    ->label('Approve')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (ProductReview $record): bool => ! $record->approved)
                    ->authorize('approve')
                    ->requiresConfirmation()
                    ->action(fn (ProductReview $record, ApproveProductReview $approveProductReview) => $approveProductReview->handle($record, auth()->user())),
                Action::make('unapprove')
                    ->label('Unapprove')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (ProductReview $record): bool => $record->approved)
                    ->authorize('approve')
                    ->requiresConfirmation()
                    ->action(fn (ProductReview $record, UnapproveProductReview $unapproveProductReview) => $unapproveProductReview->handle($record, auth()->user())),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('approve')
                        ->label('Approve selected')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->authorizeIndividualRecords('approve')
                        ->requiresConfirmation()
                        ->action(function (Collection $records, ApproveProductReview $approveProductReview): void {
                            /** @var ProductReview $review */
                            foreach ($records as $review) {
                                $approveProductReview->handle($review, auth()->user());
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
