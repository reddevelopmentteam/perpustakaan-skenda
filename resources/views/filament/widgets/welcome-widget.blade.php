<x-filament-widgets::widget class="fi-welcome-widget">
    <x-filament::section>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700/90 dark:text-emerald-300/90">{{ $date }}</p>
                <h2 class="mt-1 text-xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-2xl">
                    {{ $greeting }}, {{ $name }}
                </h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ $description }}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-3 self-start rounded-lg border border-emerald-100/80 bg-white/70 px-3 py-2.5 dark:border-emerald-900/40 dark:bg-emerald-950/20 sm:self-auto">
                <div class="flex h-9 w-9 items-center justify-center rounded-md bg-emerald-600/10 text-emerald-700 ring-1 ring-emerald-600/15 dark:bg-emerald-500/15 dark:text-emerald-300">
                    <x-filament::icon icon="heroicon-o-building-library" class="h-5 w-5" />
                </div>
                <div class="leading-tight">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Perpustakaan</p>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">SMKN 2 Banjarmasin</p>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
