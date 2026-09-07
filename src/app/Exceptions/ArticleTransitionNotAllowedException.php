<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ArticleStatus;
use RuntimeException;

/**
 * An article was asked to move to a status `ArticleStatus::allowedTransitions()`
 * does not reach from where it is.
 *
 * A domain exception rather than a false return, per ADR-0007. Carries both
 * ends of the refused move so a caller can build its own message — the panel
 * shows a notification, a seeder would let it surface.
 */
class ArticleTransitionNotAllowedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?ArticleStatus $from = null,
        public readonly ?ArticleStatus $to = null,
    ) {
        parent::__construct($message);
    }

    public static function between(ArticleStatus $from, ArticleStatus $to): self
    {
        return new self(
            sprintf('An article cannot move from %s to %s.', $from->getLabel(), $to->getLabel()),
            $from,
            $to,
        );
    }
}
