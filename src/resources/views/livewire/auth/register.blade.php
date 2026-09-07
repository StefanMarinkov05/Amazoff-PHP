<div class="mx-auto flex w-full max-w-md flex-col justify-center px-4 py-16 sm:px-6">

    <h1 class="text-2xl font-semibold tracking-tight text-ink-900">Create an account</h1>
    <p class="mt-2 text-sm text-ink-500">
        Already registered?
        <a href="/login" wire:navigate
           class="font-medium text-marine-700 underline-offset-4 hover:underline
                  focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20">
            Sign in
        </a>
    </p>

    <form wire:submit="register" class="mt-8 space-y-5" novalidate>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="first_name" class="block text-sm font-medium text-ink-800">First name</label>
                <input wire:model.blur="first_name" id="first_name" type="text"
                       autocomplete="given-name" required autofocus
                       class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                              text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                              focus:ring-4 focus:ring-marine-600/20">
                @error('first_name')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="last_name" class="block text-sm font-medium text-ink-800">Last name</label>
                <input wire:model.blur="last_name" id="last_name" type="text"
                       autocomplete="family-name" required
                       class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                              text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                              focus:ring-4 focus:ring-marine-600/20">
                @error('last_name')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>

        <div>
            <label for="email" class="block text-sm font-medium text-ink-800">Email</label>
            <input wire:model.blur="email" id="email" type="email"
                   autocomplete="username" required
                   class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                          text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                          focus:ring-4 focus:ring-marine-600/20">
            @error('email')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-ink-800">Password</label>
            <input wire:model.blur="password" id="password" type="password"
                   autocomplete="new-password" required
                   class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                          text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                          focus:ring-4 focus:ring-marine-600/20">
            @error('password')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-ink-800">
                Confirm password
            </label>
            <input wire:model.blur="password_confirmation" id="password_confirmation" type="password"
                   autocomplete="new-password" required
                   class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                          text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                          focus:ring-4 focus:ring-marine-600/20">
        </div>

        <button type="submit" wire:loading.attr="disabled"
                class="inline-flex w-full items-center justify-center rounded-control bg-ink-900 px-4 py-2.5
                       text-sm font-medium text-white transition-colors duration-200
                       hover:bg-marine-700 disabled:opacity-60
                       focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20">
            <span wire:loading.remove wire:target="register">Create account</span>
            <span wire:loading wire:target="register">Creating…</span>
        </button>
    </form>
</div>
