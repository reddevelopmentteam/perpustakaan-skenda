<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class WelcomeWidget extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.welcome-widget';

    public static function canView(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $hour = (int) now()->format('H');

        $greeting = match (true) {
            $hour >= 4 && $hour < 11 => 'Selamat pagi',
            $hour >= 11 && $hour < 15 => 'Selamat siang',
            $hour >= 15 && $hour < 18 => 'Selamat sore',
            default => 'Selamat malam',
        };

        $user = auth()->user();

        return [
            'greeting' => $greeting,
            'name' => $user?->name ?? 'Pengguna',
            'date' => now()->locale('id')->translatedFormat('l, d F Y'),
            'description' => 'Ringkasan aktivitas dan informasi perpustakaan hari ini.',
        ];
    }
}
