<?php

declare(strict_types=1);

namespace App\Actions\Returns;

use App\Enums\ReturnStatus;
use App\Exceptions\IllegalReturnStatusTransitionException;
use App\Models\OrderReturn;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Staff approve or deny a return request (ADR-0020).
 *
 * The `TransitionOrderStatus` pattern one aggregate over: legality is
 * `ReturnStatus::canTransitionTo()`'s job (ADR-0004), authorization is the
 * policy's (`update_return`), and the status is re-read from the locked row
 * rather than trusted off the model passed in.
 *
 * `$from === $to` is a clean no-op checked *after* authorization — a denied
 * actor sees `AuthorizationException` even when the move is already done.
 *
 * Locks `returns`.
 */
final class ReviewReturn
{
    /**
     * @throws IllegalReturnStatusTransitionException
     */
    public function handle(OrderReturn $return, ReturnStatus $to, ?string $note, ?User $actor = null): OrderReturn
    {
        return DB::transaction(function () use ($return, $to, $note, $actor): OrderReturn {
            /** @var OrderReturn $locked */
            $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->getKey());
            $from = $locked->status;

            if ($from !== $to && ! $from->canTransitionTo($to)) {
                throw new IllegalReturnStatusTransitionException($locked, $from, $to);
            }

            if ($actor !== null) {
                Gate::forUser($actor)->authorize('update', $locked);
            }

            if ($from === $to) {
                return $locked;
            }

            $locked->update([
                'status' => $to,
                'resolution_note' => $note,
                'resolved_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
