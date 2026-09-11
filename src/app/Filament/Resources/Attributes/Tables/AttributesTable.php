<?php

declare(strict_types=1);

namespace App\Filament\Resources\Attributes\Tables;

use App\Actions\Catalogue\DeleteAttribute;
use App\Filament\Actions\DomainDeleteBulkAction;
use App\Models\Attribute;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AttributesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('input_type')
                    ->badge()
                    ->searchable(),
                IconColumn::make('is_filterable')
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->numeric()
                    ->sortable(),
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
                        fn (Attribute $record, ?User $actor) => app(DeleteAttribute::class)->handle($record, $actor),
                        'attribute',
                    ),
                    DomainDeleteBulkAction::makeAtomic(
                        fn (Attribute $record, ?User $actor) => app(DeleteAttribute::class)->handle($record, $actor),
                        'attribute',
                    ),
                ]),
            ]);
    }
}
