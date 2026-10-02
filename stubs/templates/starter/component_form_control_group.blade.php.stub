@props([
    'name'     => null,
    'label'    => null,
    'required' => false,
])

<div {{ $attributes->merge(['class' => 'space-y-1']) }}>
    @if ($label)
        <label @if($name) for="{{ $name }}" @endif class="block text-sm font-semibold text-gray-700 dark:text-gray-300">
            {{ $label }}
            @if ($required)
                <span class="text-red-500">*</span>
            @endif
        </label>
    @endif

    {{ $slot }}

    @if ($name && isset($errors) && $errors->has($name))
        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $errors->first($name) }}</p>
    @endif
</div>
