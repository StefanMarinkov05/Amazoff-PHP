<?php

declare(strict_types=1);

namespace Database\Seeders\System;

use App\Models\Carrier;
use Illuminate\Database\Seeder;

/**
 * The two couriers §15 and §16 name. Reference data, not demo content — it
 * runs in production too, for the same reason `RoleSeeder` does: delivery
 * pricing cannot resolve a carrier that has no row, so the application does
 * not work without these.
 *
 * `code` is what `App\Contracts\CourierGateway`'s implementations will key
 * off once they exist, so it is a stable identifier rather than a label —
 * renaming `name` is a display change, renaming `code` breaks the binding.
 *
 * `updateOrCreate` on `code` rather than `firstOrCreate`: re-running the
 * seeder should correct a name or reactivate a carrier someone disabled in
 * the panel, not silently skip because a row already exists.
 */
class CarrierSeeder extends Seeder
{
    /**
     * `cod_fee` is the cash-on-delivery handling surcharge each courier adds
     * on top of shipping. **These two figures are placeholders**, plausible
     * but not taken from a published tariff sheet — they differ between the
     * carriers because that difference is the whole reason the column lives
     * on `carriers` rather than in config, not because these specific
     * numbers were verified. Confirm against Econt's and Speedy's current
     * price lists before this reaches a real customer.
     *
     * @var list<array{code: string, name: string, cod_fee: string}>
     */
    private const CARRIERS = [
        ['code' => 'econt', 'name' => 'Econt', 'cod_fee' => '1.50'],
        ['code' => 'speedy', 'name' => 'Speedy', 'cod_fee' => '1.70'],
    ];

    public function run(): void
    {
        foreach (self::CARRIERS as $carrier) {
            Carrier::updateOrCreate(
                ['code' => $carrier['code']],
                [
                    'name' => $carrier['name'],
                    'cod_fee' => $carrier['cod_fee'],
                    'is_active' => true,
                ],
            );
        }
    }
}
