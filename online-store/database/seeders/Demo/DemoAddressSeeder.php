<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Saved addresses for a share of the demo customers, so `CreateOrder`'s
 * `source_address_id` path has real rows to reference instead of every
 * seeded order typing an address fresh.
 *
 * `AddressFactory`'s defaults use the app's `en_US` Faker locale — fine for
 * names, wrong for a Bulgarian storefront's cities and postcodes, so those
 * two columns are overridden from a real, small, plausible list rather than
 * left to Faker's US-shaped output.
 *
 * Left deliberately without a saved address: 40 of 100 customers, so
 * "registered but always typed the address at checkout" stays a real,
 * demonstrable state rather than something the seed makes look impossible.
 */
class DemoAddressSeeder extends Seeder
{
    private const CUSTOMERS_WITH_ADDRESS = 60;

    /** Of CUSTOMERS_WITH_ADDRESS: how many end up with two rows using different defaults. */
    private const CUSTOMERS_WITH_SECOND_DIFFERENT_DEFAULT = 15;

    /** Of CUSTOMERS_WITH_ADDRESS: how many end up with two rows but both defaults on the first. */
    private const CUSTOMERS_WITH_SECOND_SAME_DEFAULT = 3;

    /**
     * @var list<array{city: string, postcode: string}>
     */
    private const BULGARIAN_CITIES = [
        ['city' => 'Sofia', 'postcode' => '1000'],
        ['city' => 'Plovdiv', 'postcode' => '4000'],
        ['city' => 'Varna', 'postcode' => '9000'],
        ['city' => 'Burgas', 'postcode' => '8000'],
        ['city' => 'Ruse', 'postcode' => '7000'],
        ['city' => 'Stara Zagora', 'postcode' => '6000'],
        ['city' => 'Pleven', 'postcode' => '5800'],
        ['city' => 'Sliven', 'postcode' => '8800'],
        ['city' => 'Dobrich', 'postcode' => '9300'],
        ['city' => 'Shumen', 'postcode' => '9700'],
        ['city' => 'Pernik', 'postcode' => '2300'],
        ['city' => 'Haskovo', 'postcode' => '6300'],
        ['city' => 'Yambol', 'postcode' => '8600'],
        ['city' => 'Pazardzhik', 'postcode' => '4400'],
        ['city' => 'Blagoevgrad', 'postcode' => '2700'],
        ['city' => 'Veliko Tarnovo', 'postcode' => '5000'],
        ['city' => 'Vratsa', 'postcode' => '3000'],
        ['city' => 'Gabrovo', 'postcode' => '5300'],
        ['city' => 'Asenovgrad', 'postcode' => '4230'],
        ['city' => 'Vidin', 'postcode' => '3700'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        /** @var Collection<int, User> $customers */
        $customers = User::query()->doesntHave('roles')->inRandomOrder()->get();

        if ($customers->isEmpty()) {
            $this->command?->warn('No customers found — run DemoCustomerSeeder first.');

            return;
        }

        $withAddress = $customers->take(self::CUSTOMERS_WITH_ADDRESS)->values();

        // Of the 60 with an address: 18 end up with two rows, 42 with one.
        // Of the 18, the first 15 use their second address for the *other*
        // default role (a real "different billing and shipping address"
        // customer); the last 3 have a second address on file but still
        // point both defaults at the first one (a customer who saved a
        // second address without ever using it as a default — also real).
        $withSecondDifferentDefault = $withAddress->take(self::CUSTOMERS_WITH_SECOND_DIFFERENT_DEFAULT);
        $withSecondSameDefault = $withAddress->slice(
            self::CUSTOMERS_WITH_SECOND_DIFFERENT_DEFAULT,
            self::CUSTOMERS_WITH_SECOND_SAME_DEFAULT,
        );

        $created = 0;

        foreach ($withAddress as $index => $customer) {
            $this->makeAddress($customer, isDefaultBilling: true, isDefaultShipping: true);
            $created++;

            if ($withSecondDifferentDefault->contains($customer)) {
                // First address kept both defaults from the call above;
                // fix it down to billing-only now that a shipping default
                // exists elsewhere.
                Address::query()
                    ->where('user_id', $customer->getKey())
                    ->latest('id')
                    ->limit(1)
                    ->update(['is_default_shipping' => false]);

                $this->makeAddress($customer, isDefaultBilling: false, isDefaultShipping: true);
                $created++;
            } elseif ($withSecondSameDefault->contains($customer)) {
                $this->makeAddress($customer, isDefaultBilling: false, isDefaultShipping: false);
                $created++;
            }
        }

        $this->command?->info("Created {$created} address(es) for {$withAddress->count()} customer(s).");
    }

    private function makeAddress(User $customer, bool $isDefaultBilling, bool $isDefaultShipping): Address
    {
        $location = self::BULGARIAN_CITIES[array_rand(self::BULGARIAN_CITIES)];

        /** @var Address $address */
        $address = Address::factory()->create([
            'user_id' => $customer->getKey(),
            'city' => $location['city'],
            'postcode' => $location['postcode'],
            'country' => 'BG',
            'is_default_billing' => $isDefaultBilling,
            'is_default_shipping' => $isDefaultShipping,
        ]);

        return $address;
    }
}
