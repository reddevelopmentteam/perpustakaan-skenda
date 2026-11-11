@php
    $brandName = filament()->getBrandName() ?: config('app.name');
    $brandLogo = filament()->getBrandLogo();
    $brandLogoHeight = filament()->getBrandLogoHeight() ?? '1.75rem';
    $darkModeBrandLogo = filament()->getDarkModeBrandLogo();
    $hasDarkModeBrandLogo = filled($darkModeBrandLogo);

    $getLogoClasses = fn (bool $isDarkMode): string => \Illuminate\Support\Arr::toCssClasses([
        'fi-logo',
        'fi-brand-lockup flex items-center gap-2.5 font-semibold',
        'fi-logo-light' => $hasDarkModeBrandLogo && (! $isDarkMode),
        'fi-logo-dark' => $isDarkMode,
    ]);

    $logoStyles = $brandLogo ? 'height: ' . e($brandLogoHeight) : null;
@endphp

@capture($content, $logo, $isDarkMode = false)
    @if ($logo instanceof \Illuminate\Contracts\Support\Htmlable)
        <div
            {{
                $attributes
                    ->class([$getLogoClasses($isDarkMode)])
                    ->style([$logoStyles])
            }}
        >
            {{ $logo }}
        </div>
    @elseif (filled($logo))
        <div
            {{
                $attributes
                    ->class([$getLogoClasses($isDarkMode)])
                    ->style([$logoStyles])
            }}
            class="flex items-center gap-2"
        >
            <img
                alt="{{ __('filament-panels::layout.logo.alt', ['name' => $brandName]) }}"
                src="{{ $logo }}"
                style="height: {{ e($brandLogoHeight) }}"
            />
            <span class="leading-tight">
                <span class="block text-sm font-bold sm:text-[0.95rem]">{{ $brandName }}</span>
                <span class="hidden text-[0.7rem] font-medium opacity-80 sm:block">Sistem Informasi Perpustakaan</span>
            </span>
        </div>
    @else
        <div {{ $attributes->class([$getLogoClasses($isDarkMode)]) }}>
            <span class="fi-brand-mark inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-600 text-white shadow-sm ring-1 ring-emerald-700/20">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                    <path d="M11.25 4.533A9.707 9.707 0 0 0 6 3a9.735 9.735 0 0 0-3.25.555.75.75 0 0 0-.5.707v14.25a.75.75 0 0 0 1 .707A8.237 8.237 0 0 1 6 18.75c1.995 0 3.823.707 5.25 1.886V4.533ZM12.75 20.636A8.214 8.214 0 0 1 18 18.75c.966 0 1.89.166 2.75.47a.75.75 0 0 0 1-.708V4.262a.75.75 0 0 0-.5-.707A9.735 9.735 0 0 0 18 3a9.707 9.707 0 0 0-5.25 1.533v16.103Z" />
                </svg>
            </span>
            <span class="fi-brand-text flex min-w-0 max-w-[12rem] flex-col leading-tight sm:max-w-[14rem]">
                <span class="fi-brand-name whitespace-normal break-words text-[0.9rem] font-bold tracking-tight text-current sm:text-[0.95rem]">{{ $brandName }}</span>
                <span class="fi-brand-sub mt-0.5 text-[0.68rem] font-medium text-current/70">Sistem Informasi Perpustakaan</span>
            </span>
        </div>
    @endif
@endcapture

{{ $content($brandLogo) }}

@if ($hasDarkModeBrandLogo)
    {{ $content($darkModeBrandLogo, isDarkMode: true) }}
@endif
