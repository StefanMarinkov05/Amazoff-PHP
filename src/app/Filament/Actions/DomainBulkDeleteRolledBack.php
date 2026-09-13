<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use RuntimeException;

/**
 * Internal control-flow signal for {@see DomainDeleteBulkAction}'s
 * all-or-nothing mode: thrown from inside the transaction closure once a
 * refusal is seen, so Laravel rolls back every delete the loop performed.
 *
 * Never leaves the class — caught one frame up. Not an `App\Exceptions`
 * domain exception: it carries no user-facing message and must not be folded
 * into a notification by `ReportsDomainFailures`.
 */
final class DomainBulkDeleteRolledBack extends RuntimeException {}
