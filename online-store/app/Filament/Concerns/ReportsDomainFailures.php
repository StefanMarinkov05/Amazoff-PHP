<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use RuntimeException;
use Throwable;

/**
 * Turns an Action's domain exception into a notification instead of a 500.
 *
 * ADR-0007 makes failure a domain exception so the message reaching the user
 * is the caller's decision. In a panel that decision is a notification: a
 * refusal like "this variation has stock history" is something an
 * administrator can act on, and a stack trace is not.
 *
 * Only `App\Exceptions` are caught. A `QueryException` or a `TypeError` is a
 * defect rather than a refusal, and swallowing those into a toast would hide
 * exactly the failures that should be loud.
 *
 * `Halt` stops Filament's action pipeline without rolling the page back into
 * an error state, which is what leaves the notification visible.
 */
trait ReportsDomainFailures
{
    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     *
     * @throws Halt
     */
    protected function reportingDomainFailures(callable $operation, string $title): mixed
    {
        try {
            return $operation();
        } catch (RuntimeException $e) {
            if (! str_starts_with($e::class, 'App\\Exceptions\\')) {
                throw $e;
            }

            $this->notifyFailure($title, $e);

            throw new Halt;
        }
    }

    private function notifyFailure(string $title, Throwable $e): void
    {
        Notification::make()
            ->danger()
            ->title($title)
            ->body($e->getMessage())
            ->persistent()
            ->send();
    }
}
