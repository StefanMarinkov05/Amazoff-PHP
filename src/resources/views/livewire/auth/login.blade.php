<div class="mx-auto flex w-full max-w-md flex-col justify-center px-4 py-16 sm:px-6">

    <h1 class="text-2xl font-semibold tracking-tight text-ink-900">Sign in</h1>
    <p class="mt-2 text-sm text-ink-500">
        New here?
        <a href="/register" wire:navigate
           class="font-medium text-marine-700 underline-offset-4 hover:underline
                  focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20">
            Create an account
        </a>
    </p>

    {{-- Set by EnsureAccountIsActive when a session is ended mid-browse, and
         by any other redirect here that needs to say why. --}}
    @if (session('status'))
        <p role="status"
           class="mt-6 rounded-control border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm text-amber-900">
            {{ session('status') }}
        </p>
    @endif

    <form wire:submit="login" class="mt-8 space-y-5" novalidate>

        <div>
            <label for="email" class="block text-sm font-medium text-ink-800">Email</label>
            <input
                wire:model="email"
                id="email" type="email" name="email"
                autocomplete="username" required autofocus
                @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                       text-sm text-ink-900 placeholder:text-ink-400
                       focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/20"
            >
            @error('email')
                <p id="email-error" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <div class="flex items-center justify-between">
                <label for="password" class="block text-sm font-medium text-ink-800">Password</label>
                <a href="/password/reset" wire:navigate
                   class="text-xs font-medium text-marine-700 underline-offset-4 hover:underline
                          focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20">
                    Forgot your password?
                </a>
            </div>
            <input
                wire:model="password"
                id="password" type="password" name="password"
                autocomplete="current-password" required
                @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                       text-sm text-ink-900 placeholder:text-ink-400
                       focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/20"
            >
            @error('password')
                <p id="password-error" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <label class="flex items-center gap-2.5 text-sm text-ink-600">
            <input wire:model="remember" type="checkbox"
                   class="h-4 w-4 rounded border-ink-300 text-marine-700
                          focus:ring-4 focus:ring-marine-600/20">
            Remember me
        </label>

        <button
            type="submit"
            wire:loading.attr="disabled"
            class="inline-flex w-full items-center justify-center rounded-control bg-ink-900 px-4 py-2.5
                   text-sm font-medium text-white transition-colors duration-200
                   hover:bg-marine-700 disabled:opacity-60
                   focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20"
        >
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </button>
    </form>
</div>
