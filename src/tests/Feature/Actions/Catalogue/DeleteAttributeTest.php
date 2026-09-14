<?php

declare(strict_types=1);

use App\Actions\Catalogue\DeleteAttribute;
use App\Exceptions\AttributeCannotBeDeletedException;
use App\Models\Attribute;
use App\Models\AttributeValue;
use Database\Seeders\System\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * EditAttribute's default DeleteAction previously called $record->delete()
 * directly, surfacing attribute_values.attribute_id's foreign key as an
 * uncaught QueryException (1451) instead of a message naming the
 * dependency. DeleteAttribute is what EditAttribute now routes through
 * instead.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

it('deletes an attribute with no values', function (): void {
    $attribute = Attribute::factory()->create();

    app(DeleteAttribute::class)->handle($attribute, null);

    expect(Attribute::find($attribute->getKey()))->toBeNull();
});

it('refuses an attribute with a value and writes nothing', function (): void {
    $attribute = Attribute::factory()->create();
    AttributeValue::factory()->create(['attribute_id' => $attribute->getKey()]);

    expect(fn () => app(DeleteAttribute::class)->handle($attribute, null))
        ->toThrow(AttributeCannotBeDeletedException::class);

    expect(Attribute::find($attribute->getKey()))->not->toBeNull();
});

it('denies an actor without delete_attribute', function (): void {
    $attribute = Attribute::factory()->create();
    $actor = catalogueActor('update_attribute');

    expect(fn () => app(DeleteAttribute::class)->handle($attribute, $actor))
        ->toThrow(AuthorizationException::class);

    expect(Attribute::find($attribute->getKey()))->not->toBeNull();
});

it('allows an actor holding delete_attribute', function (): void {
    $attribute = Attribute::factory()->create();
    $actor = catalogueActor('delete_attribute');

    app(DeleteAttribute::class)->handle($attribute, $actor);

    expect(Attribute::find($attribute->getKey()))->toBeNull();
});
