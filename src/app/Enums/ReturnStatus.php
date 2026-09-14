<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of a customer return request (ADR-0020, CRD Arts. 9–15).
 *
 * Legality only, and deliberately role-blind — which staff member may approve
 * or refund a return is a policy question (`ReturnPolicy`). Same shape as
 * `ShipmentStatus`. Governed by ADR-0004.
 *
 * `Refunded` is the only terminal state that concludes the money; `Denied` is
 * terminal too — a customer whose request is refused opens a fresh one rather
 * than reviving the old.
 */
enum ReturnStatus: string implements HasColor, HasLabel
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Denied = 'denied';
    case Refunded = 'refunded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Approved => 'Approved',
            self::Denied => 'Denied',
            self::Refunded => 'Refunded',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Approved => 'info',
            self::Denied => 'danger',
            self::Refunded => 'success',
        };
    }

    /**
     * Governed by ADR-0004 — read it before widening or narrowing this table.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Denied],
            self::Approved => [self::Refunded],
            self::Denied, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }
}
