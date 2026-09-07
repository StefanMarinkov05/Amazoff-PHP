@props([
    'name',
    'label',
    'type' => 'text',
])

{{-- One labelled input, wired to the component property of the same name.
     Extracted because checkout asks for eleven of these and the error,
     aria-invalid, and focus-ring markup is identical on every one — the
     "no oversized Blade files, repeated markup extracted" standard (§8). --}}
<div {{ $attributes->only('class') }}>
    <label for="{{ $name }}" class="block text-sm font-medium text-ink-800">{{ $label }}</label>

    <input
        wire:model.blur="{{ $name }}"
        id="{{ $name }}"
        type="{{ $type }}"
        @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
        class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
               text-sm text-ink-900 placeholder:text-ink-400
               focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/20"
    >

    @error($name)
        <p id="{{ $name }}-error" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
