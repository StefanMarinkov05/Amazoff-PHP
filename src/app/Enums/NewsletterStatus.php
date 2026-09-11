<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Unsubscribing is a status change, not a delete — the row is what records
 * that this address asked not to be emailed. Whether unsubscribed rows are
 * eventually erased is still open; see `docs/explanation/gdpr.md`.
 */
enum NewsletterStatus: string implements HasColor, HasLabel
{
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
            self::Subscribed => 'Subscribed',
            self::Unsubscribed => 'Unsubscribed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Subscribed => 'success',
            self::Unsubscribed => 'gray',
        };
    }
}
