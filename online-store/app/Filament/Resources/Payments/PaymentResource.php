<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\RelationManagers\PaymentEventsRelationManager;
use App\Filament\Resources\Payments\Schemas\PaymentInfolist;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use App\Models\Payment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Payments, read-only apart from refunding.
 *
 * No create page and no create permission — `permissions.md` is explicit
 * that "a payment row is written by the Stripe webhook (§13)", so a
 * `create_payment` permission "could only ever be ticked by mistake". No
 * edit page either: every field here is either snapshotted from the order or
 * written by Stripe, and hand-editing a payment's amount or status would put
 * the row out of step with the money that actually moved.
 *
 * The one write is **Refund**, which goes through `RefundPayment` and is
 * gated by `refund_payment` — administrator-only, because refunding money is
 * not editing a row.
 *
 * The payment *events* relation manager is the reconciliation surface: every
 * webhook Stripe delivered, whether it applied or not and why.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            PaymentEventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
