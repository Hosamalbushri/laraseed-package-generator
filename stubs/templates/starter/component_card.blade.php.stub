@props([
    'title' => null,
    'subtitle' => null,
    'description' => null,
])

@php
    $sub = $subtitle ?? $description;
@endphp

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all hover:shadow-md dark:border-gray-800 dark:bg-gray-900']) }}>
    @if ($title || $sub)
        <div class="mb-4">
            @if ($title)
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">
                    {{ $title }}
                </h3>
            @endif

            @if ($sub)
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ $sub }}
                </p>
            @endif
        </div>
    @endif

    {{ $slot }}
</div>
