<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Models\User;
use Closure;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * A bulk delete that routes every selected record through its per-record
 * delete Action, instead of Filament's default per-record `$record->delete()`.
 *
 * The problem this exists to solve: routing a resource's *single* delete
 * through an Action — so `DeleteBrand` can refuse "12 products use this brand"
 * with a message instead of a raw foreign-key `QueryException` — does nothing
 * for the bulk path. `DeleteBulkAction` is a second, independent call site
 * that Filament wires up by default and that calls `$record->delete()`
 * directly, so every domain guard the single path gained is skipped in bulk.
 * On `Product`, which soft-deletes, the bulk delete even *succeeds* silently,
 * skipping `DeleteProduct`'s variation cascade with no error at all — a guard
 * bypassed with no trace, which is harder to notice than a 500.
 *
 * Filament v4 exposes `DeleteBulkAction::using()` (via `CanCustomizeProcess`)
 * as the one seam for replacing the process closure. This class fills that
 * seam with a loop that calls the given delete Action per record and catches
 * only `App\Exceptions\*` refusals — the same namespace filter
 * `ReportsDomainFailures` uses, and for the same reason: a `QueryException`
 * or `TypeError` is a defect that should stay loud, not a refusal to fold
 * into a toast.
 *
 * Two shapes, exposed as two bulk actions in the group so the operator picks
 * one at the point of use rather than through a modal option:
 *
 * - {@see make()} — **partial**: delete every record that can be deleted,
 *   skip the refused ones, report a summary. Matches Filament's own
 *   partial-success convention for its built-in bulk actions.
 * - {@see makeAtomic()} — **all-or-nothing**: run the whole selection inside
 *   one transaction; if *any* record is refused, roll the transaction back so
 *   nothing is deleted, and report which records blocked it. Predictable for
 *   a large selection where a half-applied delete is worse than none.
 *
 * Usage, in a resource's table `toolbarActions()`:
 *
 *     BulkActionGroup::make([
 *         DomainDeleteBulkAction::make($delete, 'brand'),
 *         DomainDeleteBulkAction::makeAtomic($delete, 'brand'),
 *     ])
 *
 * where `$delete` is `fn (Brand $r, ?User $a) => app(DeleteBrand::class)->handle($r, $a)`.
 *
 * The lookup-table resources whose delete carries no rule (`Tag`,
 * `NewsletterSubscriber`, …) keep the plain `DeleteBulkAction` — wrapping a
 * ruleless delete in this buys nothing.
 */
final class DomainDeleteBulkAction
{
    /**
     * Partial mode — the drop-in replacement for a bare `DeleteBulkAction`.
     *
     * @template TModel of Model
     *
     * @param  Closure(TModel, User|null): mixed  $delete  Calls the per-record delete Action.
     * @param  string  $modelLabel  Lowercase singular, e.g. "brand" — used in the refusal summary.
     */
    public static function make(Closure $delete, string $modelLabel): DeleteBulkAction
    {
        return self::base($delete, $modelLabel, atomic: false);
    }

    /**
     * All-or-nothing mode — a second entry in the same `BulkActionGroup`.
     *
     * @template TModel of Model
     *
     * @param  Closure(TModel, User|null): mixed  $delete
     */
    public static function makeAtomic(Closure $delete, string $modelLabel): DeleteBulkAction
    {
        return self::base($delete, $modelLabel, atomic: true)
            ->name('deleteAtomic')
            ->label('Delete selected (all or nothing)')
            ->modalDescription(fn (): string => "If any selected {$modelLabel} cannot be deleted, none of them will be.");
    }

    private static function base(Closure $delete, string $modelLabel, bool $atomic): DeleteBulkAction
    {
        $action = DeleteBulkAction::make()
            // Force real models rather than a bulk `DELETE` query: the per-record
            // Action needs the model to lock its row and count its dependents.
            ->fetchSelectedRecords();

        return $action->using(function (Collection $records) use ($action, $delete, $modelLabel, $atomic): void {
            /** @var User $actor */
            $actor = auth()->user();

            $atomic
                ? self::runAtomic($action, $records, $actor, $delete, $modelLabel)
                : self::runPartial($action, $records, $actor, $delete, $modelLabel);
        });
    }

    /**
     * @param  Collection<int, Model>  $records
     */
    private static function runPartial(
        DeleteBulkAction $bulkAction,
        Collection $records,
        User $actor,
        Closure $delete,
        string $modelLabel,
    ): void {
        $deleted = 0;
        $refusals = [];

        foreach ($records as $record) {
            try {
                $delete($record, $actor);
                $deleted++;
            } catch (RuntimeException|InvalidArgumentException $e) {
                self::rethrowIfNotDomain($e);

                $bulkAction->reportBulkProcessingFailure();
                $refusals[] = $e->getMessage();
            }
        }

        $bulkAction->reportBulkProcessingSuccessfulRecordsCount($deleted);

        if ($refusals !== []) {
            self::notifyRefusals($modelLabel, $refusals);
        }
    }

    /**
     * @param  Collection<int, Model>  $records
     */
    private static function runAtomic(
        DeleteBulkAction $bulkAction,
        Collection $records,
        User $actor,
        Closure $delete,
        string $modelLabel,
    ): void {
        $refusals = [];

        try {
            DB::transaction(function () use ($records, $actor, $delete, &$refusals): void {
                foreach ($records as $record) {
                    try {
                        $delete($record, $actor);
                    } catch (RuntimeException|InvalidArgumentException $e) {
                        self::rethrowIfNotDomain($e);

                        $refusals[] = $e->getMessage();
                    }
                }

                if ($refusals !== []) {
                    // Undo every delete this loop performed — nothing is written
                    // unless the whole selection would have succeeded.
                    throw new DomainBulkDeleteRolledBack;
                }
            });
        } catch (DomainBulkDeleteRolledBack) {
            $bulkAction->reportCompleteBulkProcessingFailure();
            self::notifyRefusals($modelLabel, $refusals, atomic: true);

            return;
        }

        $bulkAction->reportBulkProcessingSuccessfulRecordsCount($records->count());
    }

    private static function rethrowIfNotDomain(Throwable $e): void
    {
        if (! str_starts_with($e::class, 'App\\Exceptions\\')) {
            throw $e;
        }
    }

    /**
     * @param  list<string>  $refusals
     */
    private static function notifyRefusals(string $modelLabel, array $refusals, bool $atomic = false): void
    {
        $count = count($refusals);
        $plural = Str::plural($modelLabel, $count);

        $title = $atomic
            ? "No {$modelLabel} deleted — {$count} in the selection blocked it"
            : "{$count} {$plural} could not be deleted";

        Notification::make()
            ->danger()
            ->title($title)
            ->body(implode("\n", array_map(static fn (string $r): string => "• {$r}", array_unique($refusals))))
            ->persistent()
            ->send();
    }
}
