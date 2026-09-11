<?php

declare(strict_types=1);

namespace App\Filament\Resources\Returns;

use App\Filament\Resources\Returns\Pages\ListReturns;
use App\Filament\Resources\Returns\Pages\ViewReturn;
use App\Filament\Resources\Returns\RelationManagers\ReturnItemsRelationManager;
use App\Filament\Resources\Returns\Schemas\ReturnInfolist;
use App\Filament\Resources\Returns\Tables\ReturnsTable;
use App\Models\OrderReturn;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Customer returns / 14-day right of withdrawal (ADR-0020, CRD Arts. 9–15).
 *
 * Read-only apart from two writes, both header actions on `ViewReturn`:
 * **Approve / Deny** through `ReviewReturn` (`update_return`), and **Refund**
 * through `RefundReturn` (`refund_return`, administrator). No create page — a
 * return is authored by a customer on the storefront (`RequestReturn`), the
 * same as a review. No edit page — every field is either the customer's
 * request or a resolution the two Actions write.
 */
class ReturnResource extends Resource
{
    protected static ?string $model = OrderReturn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static ?string $modelLabel = 'return';

    public static function infolist(Schema $schema): Schema
    {
        return ReturnInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReturnsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ReturnItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReturns::route('/'),
            'view' => ViewReturn::route('/{record}'),
        ];
    }
}
