<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ReturnStatus;
use App\Models\OrderReturn;
use RuntimeException;

/**
 * A return status change was refused by `ReturnStatus::canTransitionTo()`
 * (ADR-0020).
 *
 * Same shape and reasoning as `IllegalOrderStatusTransitionException`: legality
 * lives on the enum (ADR-0004), and a double-submitted review reaches this when
 * `$from === $to` because no case lists itself.
 */
class IllegalReturnStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly OrderReturn $return,
        public readonly ReturnStatus $from,
        public readonly ReturnStatus $to,
    ) {
        parent::__construct(sprintf(
            'Return #%s cannot move from %s to %s.',
            $return->id,
            $from->value,
            $to->value,
        ));
    }
}
