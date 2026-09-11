<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Enums\DeliveryType;
use App\Models\OrderAddress;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrderAddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'orderAddresses';

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('type')
                    ->badge(),
                TextEntry::make('delivery_type')
                    ->badge(),
                TextEntry::make('first_name')
                    ->label('Recipient')
                    ->formatStateUsing(fn (string $state, OrderAddress $record): string => "{$state} {$record->last_name}"),
                TextEntry::make('phone'),
                TextEntry::make('city')
                    ->label('Address')
                    ->formatStateUsing(fn (OrderAddress $record): string => self::formatAddress($record))
                    ->columnSpanFull(),
                TextEntry::make('source_address_id')
                    ->label('Address source')
                    ->placeholder('Entered at checkout')
                    ->formatStateUsing(fn (): string => 'From a saved address'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (OrderAddress $record): string => $record->type->getLabel())
            ->defaultSort('type', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('delivery_type')
                    ->badge(),
                TextColumn::make('first_name')
                    ->label('Recipient')
                    ->formatStateUsing(fn (string $state, OrderAddress $record): string => "{$state} {$record->last_name}")
                    ->description(fn (OrderAddress $record): string => $record->phone)
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('city')
                    ->label('Destination')
                    ->formatStateUsing(fn (OrderAddress $record): string => self::formatAddress($record))
                    ->wrap()
                    ->searchable(['city', 'street', 'courier_office_name']),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->headerActions([])
            ->recordActions([
                ViewAction::make()
                    ->modalHeading(fn (OrderAddress $record): string => $record->type->getLabel()),
            ])
            ->toolbarActions([]);
    }

    private static function formatAddress(OrderAddress $address): string
    {
        $destination = $address->delivery_type === DeliveryType::Office
            ? trim("{$address->courier_office_name} ({$address->courier_office_code})")
            : (string) $address->street;

        return implode(', ', array_filter([
            $destination,
            trim("{$address->postcode} {$address->city}"),
            $address->country,
        ]));
    }
}
