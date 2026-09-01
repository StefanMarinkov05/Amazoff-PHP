<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Every Stripe webhook delivered for this payment — the reconciliation
 * surface. Read-only: these rows are written by
 * `HandleStripeWebhookEvent` and are the audit trail, not an editable list.
 *
 * `note` is the column that earns this table its place: it says why an event
 * did *not* apply — a duplicate, an out-of-order delivery, or a status the
 * payment already held — which is otherwise invisible from the payment row.
 */
class PaymentEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentEvents';

    protected static ?string $title = 'Stripe events';

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('stripe_event_id')
                    ->label('Stripe event')
                    ->fontFamily('mono'),
                TextEntry::make('event_type')
                    ->label('Type'),
                TextEntry::make('status_before')
                    ->badge()
                    ->placeholder('—'),
                TextEntry::make('status_after')
                    ->badge(),
                TextEntry::make('note')
                    ->placeholder('Applied')
                    ->columnSpanFull(),
                TextEntry::make('processed_at')
                    ->dateTime(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('stripe_event_id')
            ->defaultSort('processed_at', 'desc')
            ->columns([
                TextColumn::make('processed_at')
                    ->label('Received')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('event_type')
                    ->label('Type')
                    ->searchable(),
                TextColumn::make('status_before')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('status_after')
                    ->badge(),
                TextColumn::make('note')
                    ->placeholder('Applied')
                    ->wrap(),
                TextColumn::make('stripe_event_id')
                    ->label('Stripe event')
                    ->fontFamily('mono')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
