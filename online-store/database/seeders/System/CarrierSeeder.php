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
 * `code` is what `App\Support\Courier\CourierManager::for()` keys drivers
 * off (`Courier::for($carrier)` resolves the `econt` or `speedy` driver by
 * this column), so it is a stable identifier rather than a label — renaming
 * `name` is a display change, renaming `code` breaks the binding.
 *
 * `updateOrCreate` on `code` rather than `firstOrCreate`: re-running the
 * seeder should correct a name or reactivate a carrier someone disabled in
 * the panel, not silently skip because a row already exists.
 */
class CarrierSeeder extends Seeder
{
    /**
     * `cod_fee` is the cash-on-delivery handling surcharge each courier adds
     * on top of shipping; `base_delivery_price` is what `CalculateDeliveryPrice`
     * charges when the carrier's own quote endpoint is unreachable. **All
     * three figures are placeholders**, plausible but not taken from a
     * published tariff sheet — they differ between the carriers because
     * that difference is the whole reason these columns live on `carriers`
     * rather than in config, not because the specific numbers were
     * verified. Confirm against Econt's and Speedy's current price lists
     * before this reaches a real customer.
     *
     * @var list<array{code: string, name: string, cod_fee: string, base_delivery_price: string}>
     */
    private const CARRIERS = [
        ['code' => 'econt', 'name' => 'Econt', 'cod_fee' => '1.50', 'base_delivery_price' => '5.99'],
        ['code' => 'speedy', 'name' => 'Speedy', 'cod_fee' => '1.70', 'base_delivery_price' => '6.49'],
    ];

    public function run(): void
    {
        foreach (self::CARRIERS as $carrier) {
            Carrier::updateOrCreate(
                ['code' => $carrier['code']],
                [
                    'name' => $carrier['name'],
                    'cod_fee' => $carrier['cod_fee'],
                    'base_delivery_price' => $carrier['base_delivery_price'],
                    'is_active' => true,
                ],
            );
        }
    }
}
