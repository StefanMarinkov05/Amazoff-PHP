<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Double opt-in (ePrivacy Art. 13, ADR-0019):
 *
 * - `Pending`     — the address was submitted; a confirmation email is out,
 *                   nothing is ever sent to a `Pending` row.
 * - `Subscribed`  — the confirmation link was clicked. Only these are mailed.
 * - `Unsubscribed`— asked not to be emailed. Kept as the record of that.
 *
 * Unsubscribing is a status change, not a delete. Whether unsubscribed and
 * never-confirmed rows are eventually erased is in `docs/explanation/gdpr.md`
 * ("Open") and `newsletter:purge-unconfirmed` handles the second.
 */
enum NewsletterStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Subscribed = 'subscribed';
    case Unsubscribed = 'unsubscribed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending confirmation',
            self::Subscribed => 'Subscribed',
            self::Unsubscribed => 'Unsubscribed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Subscribed => 'success',
            self::Unsubscribed => 'gray',
        };
    }
}
