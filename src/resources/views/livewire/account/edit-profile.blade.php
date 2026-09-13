<div class="mx-auto flex w-full max-w-md flex-col justify-center px-4 py-16 sm:px-6">

    <h1 class="text-2xl font-semibold tracking-tight text-ink-900">Your profile</h1>
    <p class="mt-2 text-sm text-ink-500">
        Update your name, email, and phone number.
    </p>

    @if ($saved)
        <div role="status"
             class="mt-6 rounded-card border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            Profile updated.
        </div>
    @endif

    <form wire:submit="save" class="mt-8 space-y-5" novalidate>

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
            <label for="email" class="block text-sm font-medium text-ink-800">Email</label>
            <input wire:model="email" id="email" type="email" autocomplete="email" required
                   class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                          text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                          focus:ring-4 focus:ring-marine-600/20">
            @error('email')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="phone" class="block text-sm font-medium text-ink-800">Phone</label>
            <input wire:model="phone" id="phone" type="tel" autocomplete="tel"
                   class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                          text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                          focus:ring-4 focus:ring-marine-600/20">
            @error('phone')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <button type="submit" wire:loading.attr="disabled"
                class="inline-flex w-full items-center justify-center rounded-control bg-ink-900 px-4 py-2.5
                       text-sm font-medium text-white transition-colors duration-200
                       hover:bg-marine-700 disabled:opacity-60
                       focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20">
            <span wire:loading.remove wire:target="save">Save changes</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </form>
</div>
