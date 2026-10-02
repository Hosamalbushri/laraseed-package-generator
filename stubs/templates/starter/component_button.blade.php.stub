@props([
    'variant' => 'primary',
    'size'    => 'md',
    'href'    => null,
    'type'    => 'button',
])

@php
    $baseClasses = 'inline-flex items-center justify-center font-bold text-decoration-none rounded-lg transition-all focus:outline-none focus:ring-2 focus:ring-offset-2 cursor-pointer';

    $sizeClasses = match ($size) {
        'sm'    => 'px-2.5 py-1.5 text-xs gap-1.5',
        'lg'    => 'px-6 py-3 text-base gap-3',
        default => 'px-4 py-2 text-sm gap-2',
    };

    $variantClasses = match ($variant) {
        'secondary' => 'bg-gray-100 text-gray-800 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 focus:ring-gray-500',
        'outline'   => 'border border-gray-300 bg-transparent text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 focus:ring-[var(--brand-color)]',
        'danger'    => 'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500',
        default     => 'bg-[var(--brand-color)] text-white hover:opacity-90 focus:ring-[var(--brand-color)]',
    };

    $classes = "{$baseClasses} {$sizeClasses} {$variantClasses}";
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </button>
@endif
