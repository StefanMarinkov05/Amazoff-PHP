<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/*
 * Pint, Larastan, and Pest all passed while AddressFactory wrote a full country
 * name into a char(2) column; the failure appeared only on insert. Persisting
 * one row from every factory is what catches that class of bug, and it is the
 * check `docs/how-to/regenerate-with-blueprint.md` asks for after every
 * regeneration.
 */

dataset('factories', function (): Generator {
    // Resolved from __DIR__ rather than database_path(): dataset closures are
    // evaluated during test collection, before the application is booted.
    $paths = glob(dirname(__DIR__, 2).'/database/factories/*Factory.php');

    foreach ($paths === false ? [] : $paths as $path) {
        $name = basename($path, '.php');

        yield $name => ['Database\\Factories\\'.$name];
    }
});

it('persists a row', function (string $factoryClass): void {
    expect(class_exists($factoryClass))->toBeTrue();

    /** @var Factory<Model> $factory */
    $factory = $factoryClass::new();
    $model = $factory->create();

    expect($model)->toBeInstanceOf(Model::class)
        ->and($model->exists)->toBeTrue()
        ->and($model->fresh())->not->toBeNull();
})->with('factories');
