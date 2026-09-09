<?php

declare(strict_types=1);

namespace App\Filament\Resources\Returns\Pages;

use App\Actions\Returns\RefundReturn;
use App\Actions\Returns\ReviewReturn;
use App\Enums\ReturnStatus;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Filament\Resources\Returns\ReturnResource;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * The two writes on a return.
 *
 * **Approve / Deny** → `ReviewReturn`, authorized `update` (`update_return`).
 * **Refund** → `RefundReturn`, authorized `refund` (`refund_return`,
 * administrator). Each Action re-authorizes and re-checks status inside its
 * own lock, so a hidden button is convenience, not the control (CLAUDE.md).
 */
class ViewReturn extends ViewRecord
{
    use ReportsDomainFailures;

    protected static string $resource = ReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->authorize('update')
                ->visible(fn (OrderReturn $record): bool => $record->status === ReturnStatus::Requested)
                ->schema([
                    Textarea::make('resolution_note')
                        ->label('Note')
                        ->helperText('Shown to the customer alongside the return status.')
                        ->maxLength(2000)
                        ->nullable(),
                ])
                ->action(fn (OrderReturn $record, array $data) => $this->review($record, ReturnStatus::Approved, $data)),

            Action::make('deny')
                ->label('Deny')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->authorize('update')
                ->visible(fn (OrderReturn $record): bool => $record->status === ReturnStatus::Requested)
                ->schema([
                    Textarea::make('resolution_note')
                        ->label('Reason for denial')
                        ->required()
                        ->maxLength(2000),
                ])
                ->action(fn (OrderReturn $record, array $data) => $this->review($record, ReturnStatus::Denied, $data)),

            Action::make('refund')
                ->label('Refund')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('warning')
                ->authorize('refund')
                ->visible(fn (OrderReturn $record): bool => $record->status === ReturnStatus::Approved)
                ->requiresConfirmation()
                ->modalHeading('Refund this return?')
                ->modalDescription(fn (OrderReturn $record): string => sprintf(
                    'Goods value %s will be refunded (card refund via Stripe, or recorded for offline cash '
                    .'handling on a COD order) and the items restocked.',
                    self::goodsTotal($record),
                ))
                ->action(function (OrderReturn $record): void {
                    $this->reportingDomainFailures(
                        fn () => app(RefundReturn::class)->handle($record, auth()->user()),
                        'Refund could not be completed',
                    );

                    $this->record = $record->fresh();
                }),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function review(OrderReturn $record, ReturnStatus $to, array $data): void
    {
        $note = $data['resolution_note'] ?? null;

        $this->reportingDomainFailures(
            fn () => app(ReviewReturn::class)->handle(
                $record,
                $to,
                is_string($note) && $note !== '' ? $note : null,
                auth()->user(),
            ),
            'Return could not be updated',
        );

        $this->record = $record->fresh();
    }

    private static function goodsTotal(OrderReturn $return): Money
    {
        $total = Money::zero();

        foreach ($return->returnItems()->with('orderItem')->get() as $item) {
            /** @var OrderItem $orderItem */
            $orderItem = $item->orderItem;
            $total = $total->add(Money::of((string) $orderItem->unit_price)->multiply($item->quantity));
        }

        return $total;
    }
}
