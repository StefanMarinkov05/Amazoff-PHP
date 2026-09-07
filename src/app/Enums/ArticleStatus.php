<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ArticleStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Published = 'published';
    case Scheduled = 'scheduled';
    case Archived = 'archived';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Scheduled => 'Scheduled',
            self::Archived => 'Archived',
        };
    }

    /**
     * Governed by ADR-0004 — read it before widening or narrowing this table.
     *
     * `Published => Draft` un-publishes a live article rather than archiving
     * it. Deliberate rather than an oversight: pulling a factually wrong
     * article back for editing is a real need, and archiving implies the
     * article is finished rather than broken.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Scheduled, self::Published],
            self::Scheduled => [self::Draft, self::Published],
            self::Published => [self::Draft, self::Archived],
            self::Archived => [self::Draft, self::Published],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Published => 'success',
            self::Scheduled => 'info',
            self::Archived => 'warning',
        };
    }

    /**
     * Whether the article may appear on the public site. `Scheduled` is
     * deliberately excluded — the scheduler flips it to `Published` when its
     * publication date arrives, rather than the storefront comparing dates.
     */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }
}
