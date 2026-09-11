<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;

/*
 * Shared harness for every test in tests/Concurrency. Why races need two real
 * processes at all — and how to choose the assertion once they have run — is
 * `explanation/concurrency-and-locking.md`; this file is only the mechanics.
 *
 * Loaded via composer.json's autoload-dev `files`, not by Pest's own
 * discovery: a helper defined in a sibling *test* file is undefined when its
 * consumer runs alone under --filter, the same trap tests/Pest.php documents.
 */

/**
 * The instant every worker in one race releases at, as microtime(true).
 *
 * Eight seconds by default because the workers boot Laravel before they can
 * wait, and a barrier shorter than the slowest boot lets them run
 * sequentially — which silently turns a race into two ordered calls and makes
 * "exactly one winner" pass while proving nothing. RACE_BARRIER_SECONDS
 * raises it for a loaded CI runner.
 */
function raceBarrierInstant(): float
{
    return microtime(true) + (float) (getenv('RACE_BARRIER_SECONDS') ?: 8.0);
}

/**
 * phpunit.xml points this suite at online_shop_test, but a spawned process
 * reads .env instead — which is the *dev* database. Passing the resolved
 * connection explicitly is what keeps a race from silently running against
 * development data, and is also what makes each worker follow paratest's
 * per-worker database when one is active.
 *
 * @return array<string, string>
 */
function raceWorkerEnvironment(): array
{
    return [
        'DB_CONNECTION' => 'mysql',
        'DB_DATABASE' => config('database.connections.mysql.database'),
        'DB_HOST' => config('database.connections.mysql.host'),
        'DB_PORT' => (string) config('database.connections.mysql.port'),
        'DB_USERNAME' => config('database.connections.mysql.username'),
        'DB_PASSWORD' => config('database.connections.mysql.password'),
    ];
}

/**
 * Where one side of a rendezvous plants its ready-flag.
 *
 * `storage/framework/testing/` rather than `base_path()`: a worker killed
 * between planting its flag and unlinking it leaves the file behind, and
 * Laravel already gitignores everything under that directory. In the
 * project root the same leftover would show up as an untracked file.
 */
function raceFlagPath(string $name): string
{
    return storage_path('framework/testing/race-ready-'.$name);
}

/**
 * Starts one `race:worker` process per job and waits for all of them.
 *
 * Each job is the command's own arguments: `action`, plus optional `ids`,
 * `args`, and `rendezvous`. Pass `rendezvous` on **both** jobs of a
 * two-Action race whose sides do different amounts of work — without it boot
 * jitter, not the lock, decides who wins. `AddToCartVsMergeGuestCartConcurrencyTest`
 * is the worked example.
 *
 * @param  list<array{action: string, ids?: list<int>, args?: list<string|int>, rendezvous?: string}>  $jobs
 * @return Collection<int, string> one entry per job, 'OK' or 'FAILED:<class>'
 */
function runRaceWorkers(array $jobs): Collection
{
    $startAt = raceBarrierInstant();
    $environment = raceWorkerEnvironment();
    $names = array_column($jobs, 'rendezvous');

    $processes = collect($jobs)->map(function (array $job) use ($startAt, $environment, $names): Process {
        $command = [
            'php',
            'artisan',
            'race:worker',
            $job['action'],
            '--start-at='.$startAt,
        ];

        foreach ($job['ids'] ?? [] as $id) {
            $command[] = '--id='.$id;
        }

        foreach ($job['args'] ?? [] as $arg) {
            $command[] = '--arg='.$arg;
        }

        if (isset($job['rendezvous'])) {
            // Each side waits for the *other* side's file. Two-sided by
            // construction: a rendezvous only one worker participates in
            // would block until its timeout and prove nothing.
            $peer = collect($names)->first(fn (?string $n): bool => $n !== null && $n !== $job['rendezvous']);

            $command[] = '--ready-file='.raceFlagPath($job['rendezvous']);
            $command[] = '--peer-file='.raceFlagPath((string) $peer);
        }

        $process = new Process($command, base_path(), $environment);
        $process->start();

        return $process;
    });

    $processes->each(fn (Process $p) => $p->wait());

    return $processes->map(fn (Process $p) => trim($p->getOutput().$p->getErrorOutput()));
}

/**
 * The failure message every race assertion appends. A race that fails tells
 * you almost nothing without the workers' own output — "expected 1, got 0"
 * usually means both failed to boot, not that a lock is broken.
 *
 * @param  Collection<int, string>  $outputs
 */
function raceReport(Collection $outputs): string
{
    return "\nWorker output was:\n".$outputs->implode("\n---\n");
}
