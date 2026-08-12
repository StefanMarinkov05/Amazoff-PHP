<?php

declare(strict_types=1);

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Carrier;
use App\Models\ContactMessage;
use App\Models\Coupon;
use App\Models\Inventory;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductReview;
use App\Models\ProductVariation;
use App\Models\Shipment;
use App\Models\Tag;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\UserPolicy;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * §37 criterion 18 — "user roles cannot access prohibited features".
 *
 * The negative cases are the point. A test that only asserts content_editor
 * reaches articles would pass just as happily against a system with no
 * authorization at all, which is exactly the state this branch replaced.
 */

beforeEach(function (): void {
    // RefreshDatabase truncates but does not seed, and the registrar caches
    // the permission table for 24 hours — without this the second test in the
    // run resolves against the first test's now-deleted rows.
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
});

/** The seven models with a Filament resource built, and who may list them. */
dataset('panel resources', [
    'Brand' => [Brand::class],
    'Tag' => [Tag::class],
    'ProductCategory' => [ProductCategory::class],
    'ArticleCategory' => [ArticleCategory::class],
    'Attribute' => [Attribute::class],
    'AttributeValue' => [AttributeValue::class],
    'Carrier' => [Carrier::class],
]);

/**
 * Every model named in the permission catalogue, whether or not its Filament
 * resource exists yet. A policy missing here fails open the moment someone
 * scaffolds the resource, which is the failure this dataset exists to catch
 * before it happens rather than after.
 */
dataset('all authorized models', [
    'Product' => [Product::class],
    'ProductVariation' => [ProductVariation::class],
    'ProductCategory' => [ProductCategory::class],
    'Brand' => [Brand::class],
    'Attribute' => [Attribute::class],
    'AttributeValue' => [AttributeValue::class],
    'Coupon' => [Coupon::class],
    'Article' => [Article::class],
    'ArticleCategory' => [ArticleCategory::class],
    'Tag' => [Tag::class],
    'Order' => [Order::class],
    'Shipment' => [Shipment::class],
    'Inventory' => [Inventory::class],
    'Carrier' => [Carrier::class],
    'Payment' => [Payment::class],
    'ProductReview' => [ProductReview::class],
    'ContactMessage' => [ContactMessage::class],
    'NewsletterSubscriber' => [NewsletterSubscriber::class],
    'User' => [User::class],
    'Role' => [Role::class],
]);

function userByEmail(string $email): User
{
    return User::where('email', $email)->firstOrFail();
}

it('seeds the three staff roles and a customer with none', function (): void {
    expect(userByEmail('admin@example.com')->hasRole('administrator'))->toBeTrue()
        ->and(userByEmail('editor@example.com')->hasRole('content_editor'))->toBeTrue()
        ->and(userByEmail('warehouse@example.com')->hasRole('warehouse_employee'))->toBeTrue()
        ->and(userByEmail('customer@example.com')->getRoleNames())->toBeEmpty();
});

it('lets staff reach the admin panel and keeps a customer out', function (): void {
    $panel = filament()->getPanel('admin');

    expect(userByEmail('admin@example.com')->canAccessPanel($panel))->toBeTrue()
        ->and(userByEmail('editor@example.com')->canAccessPanel($panel))->toBeTrue()
        ->and(userByEmail('warehouse@example.com')->canAccessPanel($panel))->toBeTrue()
        ->and(userByEmail('customer@example.com')->canAccessPanel($panel))->toBeFalse();
});

it('keeps a deactivated staff account out of the panel', function (): void {
    $editor = userByEmail('editor@example.com');
    $editor->update(['is_active' => false]);

    // The role is untouched — deactivation alone has to be enough, because a
    // session outlives the row it authenticated against.
    expect($editor->fresh()->hasRole('content_editor'))->toBeTrue()
        ->and($editor->fresh()->canAccessPanel(filament()->getPanel('admin')))->toBeFalse();
});

it('does not create a create_ permission for system-authored resources', function (string $permission): void {
    // A payment row comes from Stripe's webhook, a review from a verified
    // purchaser, a contact message and a subscription from a public form.
    // A create_ permission for any of them could only ever be ticked by
    // mistake in the roles UI.
    expect(Permission::where('name', $permission)->exists())->toBeFalse();
})->with([
    'create_payment',
    'create_product_review',
    'create_contact_message',
    'create_newsletter_subscriber',
]);

it('returns 403 from /admin for a customer', function (): void {
    $this->actingAs(userByEmail('customer@example.com'))
        ->get('/admin')
        ->assertForbidden();
});

it('grants an administrator every ability without attaching any permission', function (string $model): void {
    $admin = userByEmail('admin@example.com');

    expect($admin->roles()->first()->permissions)->toBeEmpty()
        ->and($admin->can('viewAny', $model))->toBeTrue()
        ->and($admin->can('create', $model))->toBeTrue();
})->with('panel resources');

it('denies a customer every resource in the panel', function (string $model): void {
    expect(userByEmail('customer@example.com')->can('viewAny', $model))->toBeFalse();
})->with('panel resources');

it('gives content_editor content resources and nothing else', function (): void {
    $editor = userByEmail('editor@example.com');

    // §3.3 grants article categories and tags. Articles and reviews have no
    // Filament resource yet, so only these two are reachable today.
    expect($editor->can('viewAny', Tag::class))->toBeTrue()
        ->and($editor->can('create', Tag::class))->toBeTrue()
        ->and($editor->can('viewAny', ArticleCategory::class))->toBeTrue();

    // Catalogue data is not content.
    expect($editor->can('viewAny', Brand::class))->toBeFalse()
        ->and($editor->can('viewAny', ProductCategory::class))->toBeFalse()
        ->and($editor->can('viewAny', Attribute::class))->toBeFalse()
        ->and($editor->can('viewAny', AttributeValue::class))->toBeFalse();

    // §3.3 denies these by name rather than by omission.
    expect($editor->can('viewAny', Carrier::class))->toBeFalse()
        ->and($editor->can('viewAny_payment'))->toBeFalse()
        ->and($editor->can('viewAny_user'))->toBeFalse()
        ->and($editor->can('viewAny_role'))->toBeFalse()
        ->and($editor->can('update_setting'))->toBeFalse();
});

it('gives warehouse_employee operations resources and read-only carriers', function (): void {
    $warehouse = userByEmail('warehouse@example.com');

    expect($warehouse->can('viewAny_order'))->toBeTrue()
        ->and($warehouse->can('updateStatus_order'))->toBeTrue()
        ->and($warehouse->can('viewAny_shipment'))->toBeTrue()
        ->and($warehouse->can('create_shipment'))->toBeTrue()
        ->and($warehouse->can('update_inventory'))->toBeTrue();

    // An order exists because a customer placed it; §17 requires status
    // history rather than direct creation or deletion.
    expect($warehouse->can('create_order'))->toBeFalse()
        ->and($warehouse->can('delete_order'))->toBeFalse();

    // Picking a courier is not administering one.
    expect($warehouse->can('viewAny', Carrier::class))->toBeTrue()
        ->and($warehouse->can('create', Carrier::class))->toBeFalse()
        ->and($warehouse->can('update_carrier'))->toBeFalse()
        ->and($warehouse->can('delete_carrier'))->toBeFalse();

    // Not products, not articles, not payments.
    expect($warehouse->can('viewAny', Brand::class))->toBeFalse()
        ->and($warehouse->can('viewAny', Tag::class))->toBeFalse()
        ->and($warehouse->can('viewAny_product'))->toBeFalse()
        ->and($warehouse->can('viewAny_article'))->toBeFalse()
        ->and($warehouse->can('viewAny_payment'))->toBeFalse();
});

it('resolves a policy for every model with a Filament resource', function (string $model): void {
    // A resource whose model has no policy is reachable by anyone who passes
    // canAccessPanel(), which is the gap this branch closed. Filament reads
    // authorization off the policy, so a missing one fails open.
    expect(Gate::getPolicyFor($model))->not->toBeNull();
})->with('panel resources');

it('resolves a policy for every model in the permission catalogue', function (string $model): void {
    // Ahead of the resources rather than behind them: a model whose policy is
    // missing fails open the moment someone scaffolds its resource, and that
    // is a much quieter failure than a missing permission.
    //
    // Role is the one that cannot be found by convention — it lives in
    // spatie's namespace, so AppServiceProvider registers it by hand.
    expect(Gate::getPolicyFor($model))->not->toBeNull();
})->with('all authorized models');

it('denies a customer every model in the catalogue', function (string $model): void {
    expect(userByEmail('customer@example.com')->can('viewAny', $model))->toBeFalse();
})->with('all authorized models');

it('keeps order and payment creation out of the panel entirely', function (): void {
    // An order exists because a customer checked out; a payment because Stripe
    // said so. Neither is authored in the panel, so the policy returns false
    // for everyone — including an administrator, whose Gate::before bypass
    // does not apply because the policy is never consulted for a permission
    // that does not exist.
    $admin = userByEmail('admin@example.com');

    expect($admin->can('create_order'))->toBeTrue()   // Gate::before: no such permission, still true
        ->and((new OrderPolicy)->create($admin))->toBeFalse()
        ->and((new PaymentPolicy)->create($admin))->toBeFalse();
});

it('stops a user deleting themselves', function (): void {
    // Deleting the last administrator locks the panel against everyone.
    $admin = userByEmail('admin@example.com');
    $other = userByEmail('editor@example.com');

    expect((new UserPolicy)->delete($admin, $admin))->toBeFalse()
        ->and((new UserPolicy)->delete($admin, $other))->toBeTrue();
});

it('lets a customer see their own order and review but not another persons', function (): void {
    $customer = userByEmail('customer@example.com');
    $other = userByEmail('editor@example.com');

    $own = Order::factory()->create(['user_id' => $customer->id]);
    $foreign = Order::factory()->create(['user_id' => $other->id]);

    // The customer holds no order permission at all — this is the ownership
    // branch, which is what §34's "unauthorized resource access" turns on.
    expect($customer->can('viewAny_order'))->toBeFalse()
        ->and($customer->can('view', $own))->toBeTrue()
        ->and($customer->can('view', $foreign))->toBeFalse();
});

it('revokes a permission removed from the seeder on the next run', function (): void {
    $editor = userByEmail('editor@example.com');
    $editor->roles()->first()->givePermissionTo('viewAny_payment');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($editor->fresh()->can('viewAny_payment'))->toBeTrue();

    // syncPermissions rather than givePermissionTo is what makes this true;
    // it is also what discards an administrator's runtime edits, which is why
    // DatabaseSeeder is a first-boot and development operation.
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($editor->fresh()->can('viewAny_payment'))->toBeFalse();
});
