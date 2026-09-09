<div class="mx-auto w-full max-w-2xl px-4 py-16 sm:px-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-ink-900">Your addresses</h1>
            <p class="mt-2 text-sm text-ink-500">Saved addresses, for your own reference.</p>
        </div>

        @if ($editingId === null)
            <button wire:click="startAdding" type="button"
                    class="rounded-control bg-ink-900 px-4 py-2 text-sm font-medium text-white hover:bg-marine-700">
                Add address
            </button>
        @endif
    </div>

    @if ($saved)
        <div role="status"
             class="mt-6 rounded-card border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            Address saved.
        </div>
    @endif

    @if ($editingId !== null)
        <form wire:submit="save" class="mt-6 space-y-5 rounded-card border border-ink-200 bg-white p-5" novalidate>
            <h2 class="text-sm font-semibold text-ink-900">
                {{ $editingId === 0 ? 'Add address' : 'Edit address' }}
            </h2>

            <div>
                <label for="label" class="block text-sm font-medium text-ink-800">Label (optional)</label>
                <input wire:model="label" id="label" type="text" placeholder="Home, Work…"
                       class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                              text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                              focus:ring-4 focus:ring-marine-600/20">
                @error('label')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="first_name" class="block text-sm font-medium text-ink-800">First name</label>
                    <input wire:model="first_name" id="first_name" type="text" required
                           class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                                  text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                                  focus:ring-4 focus:ring-marine-600/20">
                    @error('first_name')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="last_name" class="block text-sm font-medium text-ink-800">Last name</label>
                    <input wire:model="last_name" id="last_name" type="text" required
                           class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                                  text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                                  focus:ring-4 focus:ring-marine-600/20">
                    @error('last_name')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label for="phone" class="block text-sm font-medium text-ink-800">Phone</label>
                <input wire:model="phone" id="phone" type="tel" required
                       class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                              text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                              focus:ring-4 focus:ring-marine-600/20">
                @error('phone')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="country" class="block text-sm font-medium text-ink-800">Country</label>
                    <input wire:model="country" id="country" type="text" maxlength="2" required
                           class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                                  text-sm uppercase text-ink-900 focus:border-marine-600 focus:outline-none
                                  focus:ring-4 focus:ring-marine-600/20">
                    @error('country')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="city" class="block text-sm font-medium text-ink-800">City</label>
                    <input wire:model="city" id="city" type="text" required
                           class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                                  text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                                  focus:ring-4 focus:ring-marine-600/20">
                    @error('city')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label for="postcode" class="block text-sm font-medium text-ink-800">Postcode</label>
                <input wire:model="postcode" id="postcode" type="text" required
                       class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                              text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                              focus:ring-4 focus:ring-marine-600/20">
                @error('postcode')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="street" class="block text-sm font-medium text-ink-800">Street</label>
                <input wire:model="street" id="street" type="text" required
                       class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                              text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                              focus:ring-4 focus:ring-marine-600/20">
                @error('street')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="flex gap-6">
                <label class="flex items-center gap-2 text-sm text-ink-700">
                    <input wire:model="is_default_billing" type="checkbox"
                           class="rounded border-ink-300 text-marine-600 focus:ring-marine-600/20">
                    Default billing address
                </label>
                <label class="flex items-center gap-2 text-sm text-ink-700">
                    <input wire:model="is_default_shipping" type="checkbox"
                           class="rounded border-ink-300 text-marine-600 focus:ring-marine-600/20">
                    Default shipping address
                </label>
            </div>

            <div class="flex gap-3">
                <button type="submit" wire:loading.attr="disabled"
                        class="rounded-control bg-ink-900 px-4 py-2.5 text-sm font-medium text-white
                               hover:bg-marine-700 disabled:opacity-60">
                    <span wire:loading.remove wire:target="save">Save address</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
                <button wire:click="cancelEditing" type="button"
                        class="rounded-control border border-ink-300 px-4 py-2.5 text-sm font-medium text-ink-700
                               hover:bg-ink-50">
                    Cancel
                </button>
            </div>
        </form>
    @endif

    <div class="mt-6 space-y-4">
        @forelse ($this->addresses as $address)
            <div wire:key="address-{{ $address->id }}"
                 class="rounded-card border border-ink-200 bg-white p-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        @if ($address->label)
                            <p class="text-sm font-semibold text-ink-900">{{ $address->label }}</p>
                        @endif
                        <p class="text-sm text-ink-700">{{ $address->first_name }} {{ $address->last_name }}</p>
                        <p class="text-sm text-ink-500">{{ $address->phone }}</p>
                        <p class="mt-1 text-sm text-ink-600">
                            {{ $address->street }}, {{ $address->city }} {{ $address->postcode }}, {{ $address->country }}
                        </p>
                        <div class="mt-2 flex gap-2">
                            @if ($address->is_default_billing)
                                <span class="rounded-full bg-marine-50 px-2 py-0.5 text-xs font-medium text-marine-700">
                                    Default billing
                                </span>
                            @endif
                            @if ($address->is_default_shipping)
                                <span class="rounded-full bg-marine-50 px-2 py-0.5 text-xs font-medium text-marine-700">
                                    Default shipping
                                </span>
                            @endif
                        </div>
                    </div>

                    <div class="flex shrink-0 gap-2">
                        <button wire:click="startEditing({{ $address->id }})" type="button"
                                class="text-sm font-medium text-marine-600 hover:text-marine-700">
                            Edit
                        </button>
                        <button wire:click="delete({{ $address->id }})" type="button"
                                wire:confirm="Delete this address?"
                                class="text-sm font-medium text-red-600 hover:text-red-700">
                            Delete
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <p class="rounded-card border border-dashed border-ink-200 px-4 py-8 text-center text-sm text-ink-400">
                No saved addresses yet.
            </p>
        @endforelse
    </div>
</div>
