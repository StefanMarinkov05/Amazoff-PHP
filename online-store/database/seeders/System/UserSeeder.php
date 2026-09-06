<?php

declare(strict_types=1);

namespace Database\Seeders\System;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One demo account per role, plus a plain customer.
 *
 * §37 criterion 18 — "user roles cannot access prohibited features" — is only
 * demonstrable if there is an account per role to demonstrate it with, and the
 * customer matters as much as the staff accounts: it is the account that
 * proves canAccessPanel() denies someone holding no role at all.
 *
 * Never runs in production. Every account here has a known password, which is
 * the whole point locally and a default credential anywhere else. ADR-0003
 * lists roles, carriers, and VAT rates as production reference data; staff
 * accounts are absent from that list on purpose.
 */
class UserSeeder extends Seeder
{
    /**
     * Email, first name, last name, and the role to assign — null for the
     * customer, who holds no role at all rather than a `customer` role.
     * A registered customer is the default authenticated state, and their
     * access to their own orders is an ownership check in a policy, not a
     * permission. See the §3 note in docs/reference/specification.md.
     *
     * @var list<array{email: string, first_name: string, last_name: string, role: string|null}>
     */
    private const ACCOUNTS = [
        ['email' => 'admin@example.com', 'first_name' => 'Admin', 'last_name' => 'User', 'role' => 'administrator'],
        ['email' => 'editor@example.com', 'first_name' => 'Elena', 'last_name' => 'Editor', 'role' => 'content_editor'],
        ['email' => 'warehouse@example.com', 'first_name' => 'Wesley', 'last_name' => 'Warehouse', 'role' => 'warehouse_employee'],
        ['email' => 'customer@example.com', 'first_name' => 'Cvetan', 'last_name' => 'Customer', 'role' => null],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        foreach (self::ACCOUNTS as $account) {
            // firstOrCreate on email keeps a re-seed from colliding with the
            // unique index; the factory supplies the password and the rest.
            /** @var array<string, mixed> $attributes */
            $attributes = User::factory()->raw([
                'email' => $account['email'],
                'first_name' => $account['first_name'],
                'last_name' => $account['last_name'],
            ]);

            $user = User::firstOrCreate(['email' => $account['email']], $attributes);

            if ($account['role'] !== null) {
                // assignRole is additive but does not duplicate, so this is
                // safe to re-run. syncRoles would be wrong here: it would
                // strip a second role an administrator deliberately added.
                $user->assignRole($account['role']);
            }
        }
    }
}
