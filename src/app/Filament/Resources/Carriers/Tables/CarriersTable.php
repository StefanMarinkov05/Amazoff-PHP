<?php

declare(strict_types=1);

namespace App\Filament\Resources\Carriers\Tables;

use App\Actions\Shipment\DeleteCarrier;
use App\Filament\Actions\DomainDeleteBulkAction;
use App\Models\Carrier;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CarriersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('cod_fee')
                    ->money('EUR'),
                IconColumn::make('is_active')
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
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Routes each selected row through DeleteCarrier so the
                    // shipments foreign key surfaces as "carrier still has
                    // shipments" rather than an uncaught QueryException — the
                    // single-delete path (EditCarrier) already does this, and
                    // the bulk path is a separate call site that needs it too.
                    DomainDeleteBulkAction::make(
                        fn (Carrier $record, ?User $actor) => app(DeleteCarrier::class)->handle($record, $actor),
                        'carrier',
                    ),
                    DomainDeleteBulkAction::makeAtomic(
                        fn (Carrier $record, ?User $actor) => app(DeleteCarrier::class)->handle($record, $actor),
                        'carrier',
                    ),
                ]),
            ]);
    }
}
