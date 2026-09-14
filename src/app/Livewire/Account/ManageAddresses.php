<?php

declare(strict_types=1);

namespace App\Livewire\Account;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A signed-in customer's saved address book (§4–5's missing
 * `/account/addresses` page).
 *
 * No Action: each write is one INSERT/UPDATE/DELETE on one table, scoped to
 * the owner, with no invariant beyond "at most one default per kind" — kept
 * as an application-level swap (see `clearOtherDefaults()`) rather than a
 * schema constraint, the same tradeoff `ProductImage`'s single-`is_main`
 * rule makes, since a partial unique index on a boolean is portable but
 * awkward and this table is never write-contended the way stock or coupon
 * redemption are.
 *
 * Deliberately **not wired into checkout** — `CheckoutPage` still collects
 * address fields inline on every order, same as before this page existed.
 * Reading a saved address into checkout is a real feature (a picker, a
 * "save this address" checkbox) that touches `CheckoutPage`'s own validated
 * fields and deserves its own change, not a side effect of giving the
 * address book somewhere to live.
 *
 * @property-read Collection<int, Address> $addresses
 */
#[Layout('components.layouts.app')]
class ManageAddresses extends Component
{
    /**
     * Null when the form is closed; an id when editing; 0 when adding.
     *
     * `#[Locked]`: only ever set server-side via `startAdding()`/
     * `startEditing()`/`cancelEditing()`/`save()`/`delete()` — the blade view
     * only reads it. `save()`/`delete()` already owner-scope the lookup, so
     * this closes a crash, not an IDOR. Without the lock, a client
     * `$set('editingId', <34-digit>)` throws a `TypeError` at hydration —
     * see `SEC-014`.
     */
    #[Locked]
    public ?int $editingId = null;

    public string $label = '';

    public string $first_name = '';

    public string $last_name = '';

    public string $phone = '';

    public string $country = 'BG';

    public string $city = '';

    public string $postcode = '';

    public string $street = '';

    public bool $is_default_billing = false;

    public bool $is_default_shipping = false;

    public bool $saved = false;

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'label' => 'nullable|string|max:50',
            'first_name' => 'required|string|min:2|max:50',
            'last_name' => 'required|string|min:2|max:50',
            'phone' => 'required|string|max:30',
            'country' => 'required|string|size:2',
            'city' => 'required|string|max:50',
            'postcode' => 'required|string|max:20',
            'street' => 'required|string|max:150',
            'is_default_billing' => 'boolean',
            'is_default_shipping' => 'boolean',
        ];
    }

    /**
     * @return Collection<int, Address>
     */
    #[Computed]
    public function addresses(): Collection
    {
        return $this->user()->addresses()->latest('id')->get();
    }

    public function startAdding(): void
    {
        $this->reset([
            'label', 'first_name', 'last_name', 'phone',
            'city', 'postcode', 'street', 'is_default_billing', 'is_default_shipping',
        ]);
        $this->country = 'BG';
        $this->saved = false;
        $this->editingId = 0;
        $this->resetErrorBag();
    }

    public function startEditing(int $addressId): void
    {
        $address = $this->user()->addresses()->findOrFail($addressId);

        $this->editingId = $addressId;
        $this->label = $address->label ?? '';
        $this->first_name = $address->first_name;
        $this->last_name = $address->last_name;
        $this->phone = $address->phone;
        $this->country = $address->country;
        $this->city = $address->city;
        $this->postcode = $address->postcode;
        $this->street = $address->street;
        $this->is_default_billing = $address->is_default_billing;
        $this->is_default_shipping = $address->is_default_shipping;
        $this->saved = false;
        $this->resetErrorBag();
    }

    public function cancelEditing(): void
    {
        $this->editingId = null;
    }

    public function save(): void
    {
        $validated = $this->validate();
        $user = $this->user();

        $data = [
            'label' => $validated['label'] !== '' ? $validated['label'] : null,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'phone' => $validated['phone'],
            'country' => $validated['country'],
            'city' => $validated['city'],
            'postcode' => $validated['postcode'],
            'street' => $validated['street'],
            'is_default_billing' => $validated['is_default_billing'],
            'is_default_shipping' => $validated['is_default_shipping'],
        ];

        if ($this->editingId === 0) {
            $address = $user->addresses()->create($data);
        } else {
            /** @var Address $address */
            $address = $user->addresses()->findOrFail($this->editingId);
            $address->update($data);
        }

        $this->clearOtherDefaults($user, $address, $data['is_default_billing'], $data['is_default_shipping']);

        unset($this->addresses);
        $this->editingId = null;
        $this->saved = true;
    }

    public function delete(int $addressId): void
    {
        $this->user()->addresses()->findOrFail($addressId)->delete();

        unset($this->addresses);

        if ($this->editingId === $addressId) {
            $this->editingId = null;
        }
    }

    public function render(): View
    {
        return view('livewire.account.manage-addresses');
    }

    /**
     * At most one default billing and one default shipping address per
     * customer. Application-level rather than a schema constraint — see
     * this class's own docblock for why a partial unique index was not
     * worth it here.
     */
    private function clearOtherDefaults(User $user, Address $justSaved, bool $isBilling, bool $isShipping): void
    {
        if ($isBilling) {
            $user->addresses()
                ->where('id', '!=', $justSaved->getKey())
                ->where('is_default_billing', true)
                ->update(['is_default_billing' => false]);
        }

        if ($isShipping) {
            $user->addresses()
                ->where('id', '!=', $justSaved->getKey())
                ->where('is_default_shipping', true)
                ->update(['is_default_shipping' => false]);
        }
    }

    /**
     * The route is behind `auth` middleware, so `auth()->user()` cannot be
     * null here — narrowed explicitly rather than asserted with a docblock,
     * same reasoning `OrderHistory` gives for its own instanceof check.
     */
    private function user(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
