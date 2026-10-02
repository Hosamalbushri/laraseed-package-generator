@props([
    'id',
    'title' => null,
])

<div
    id="{{ $id }}"
    class="fixed inset-0 z-50 hidden overflow-y-auto bg-gray-900/50 backdrop-blur-sm transition-opacity font-cairo"
    tabindex="-1"
    role="dialog"
    aria-modal="true"
    aria-hidden="true"
    data-component="modal"
>
    <div class="flex min-h-screen items-center justify-center p-4 text-center sm:p-0">
        <div class="relative w-full max-w-lg transform overflow-hidden rounded-2xl bg-white p-6 text-start shadow-xl transition-all dark:bg-gray-900 border border-gray-200 dark:border-gray-800">
            @if ($title)
                <div class="flex items-center justify-between pb-4 border-b border-gray-100 dark:border-gray-800 mb-4">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">
                        {{ $title }}
                    </h3>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-900 dark:hover:bg-gray-800 dark:hover:text-white cursor-pointer bg-transparent border-0 focus:outline-none focus:ring-2 focus:ring-[var(--brand-color)]"
                        data-action="close-modal"
                        data-target="{{ $id }}"
                        aria-label="Close modal"
                    >
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            @endif

            <div class="space-y-4">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
