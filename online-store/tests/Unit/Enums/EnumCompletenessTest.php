<?php

declare(strict_types=1);

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/*
 * A sweep across every enum, deliberately asserting nothing about *which*
 * label or colour a case has.
 *
 * Restating the map would be tautological — the assertion would recompute the
 * expected value the same way the code does and could never disagree with it.
 * What is worth catching is a **missing** `match` arm, which PHP raises as
 * `UnhandledMatchError` only when that case is actually rendered. A case added
 * to an enum without a matching label arm passes Pint, passes Larastan, and
 * throws the first time an administrator opens a page showing that column.
 *
 * Enums are discovered by scanning the directory rather than listed, so a new
 * one is covered the moment it exists — the same reason FactoryTest globs.
 *
 * Paths resolve from __DIR__ because dataset closures are evaluated during
 * test collection, before the application is booted, and framework helpers
 * like app_path() do not exist yet. See troubleshooting.md.
 */

/** @return list<array{0: string}> */
function enumClasses(): array
{
    $files = glob(dirname(__DIR__, 3).'/app/Enums/*.php') ?: [];

    return array_map(
        static fn (string $path): array => ['App\\Enums\\'.basename($path, '.php')],
        $files,
    );
}

dataset('enums', enumClasses());

it('has at least one case', function (string $enum): void {
    // A guard on the sweep itself: an enum with no cases would make every
    // other assertion below pass vacuously.
    expect($enum::cases())->not->toBeEmpty();
})->with('enums');

it('resolves a label for every case', function (string $enum): void {
    if (! is_subclass_of($enum, HasLabel::class)) {
        expect(true)->toBeTrue();

        return;
    }

    foreach ($enum::cases() as $case) {
        // The call is the assertion. A missing match arm throws
        // UnhandledMatchError here instead of in the admin panel.
        expect($case->getLabel())->toBeString()->not->toBeEmpty();
    }
})->with('enums');

it('resolves a colour for every case', function (string $enum): void {
    if (! is_subclass_of($enum, HasColor::class)) {
        expect(true)->toBeTrue();

        return;
    }

    foreach ($enum::cases() as $case) {
        expect($case->getColor())->not->toBeEmpty();
    }
})->with('enums');

it('keeps values() in step with cases()', function (string $enum): void {
    if (! method_exists($enum, 'values')) {
        expect(true)->toBeTrue();

        return;
    }

    // values() is what a migration writes into an `enum()` column. If it ever
    // drifts from cases(), a fresh database gets a column that rejects a value
    // the application considers valid — and nothing reports the divergence.
    expect($enum::values())->toBe(array_column($enum::cases(), 'value'));
})->with('enums');

it('has unique backing values', function (string $enum): void {
    $values = array_column($enum::cases(), 'value');

    expect($values)->toHaveCount(count(array_unique($values)));
})->with('enums');
