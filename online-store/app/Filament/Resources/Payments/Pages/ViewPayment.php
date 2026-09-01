<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Pages;

use App\Actions\Payment\RefundPayment;
use App\Enums\PaymentStatus;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * The one write on a payment: refunding.
 *
 * `authorize('refund')` routes to `PaymentPolicy::refund()`, which
 * `permissions.md` gives to the administrator only — refunding money is not
 * editing a row. `RefundPayment` re-authorizes and re-checks the amount
 * inside its own lock, so the button being hidden is convenience, not the
 * control (CLAUDE.md: a hidden button is not security).
 */
class ViewPayment extends ViewRecord
{
    use ReportsDomainFailures;

    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refund')
                ->label('Refund')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->authorize('refund')
                ->visible(fn (Payment $record): bool => in_array(
                    $record->status,
                    [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded],
                    true,
                ) && self::remaining($record)->isPositive())
                ->modalHeading(function (Payment $record): string {
                    /** @var Order $order */
                    $order = $record->order;

                    return "Refund payment for order {$order->serial_number}?";
                })
                ->modalDescription(fn (Payment $record): string => sprintf(
                    '%s was taken, %s already refunded. Up to %s can be returned.',
                    $record->amount,
                    $record->refunded_amount,
                    (string) self::remaining($record),
                ))
                ->modalSubmitActionLabel('Send refund')
                ->schema([
                    TextInput::make('amount')
                        ->label('Amount')
                        ->helperText('Leave blank to refund everything still outstanding.')
                        ->numeric()
                        ->minValue(0.01)
                        // The real ceiling is enforced in the Action, inside
                        // the row lock — this only saves a round trip.
                        ->maxValue(fn (Payment $record): float => (float) (string) self::remaining($record))
                        ->nullable(),
                ])
                ->action(function (Payment $record, array $data): void {
                    $this->reportingDomainFailures(
                        fn () => app(RefundPayment::class)->handle(
                            $record,
                            // Blank means "the rest", which the Action reads
                            // off the row rather than trusting a number here.
                            ($data['amount'] ?? null) === null || $data['amount'] === ''
                                ? null
                                : (string) $data['amount'],
                            auth()->user(),
                        ),
                        'Refund could not be sent',
                    );

                    $this->record = $record->fresh();
                }),
        ];
    }

    private static function remaining(Payment $payment): Money
    {
        return Money::of((string) $payment->amount)
            ->subtract(Money::of((string) $payment->refunded_amount));
    }
}
