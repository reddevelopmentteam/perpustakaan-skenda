<?php

namespace App\Filament\Widgets;

use App\Models\Loan;
use Filament\Widgets\ChartWidget;

class LoansChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Tren Peminjaman 14 Hari Terakhir';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isStaff() || $user->isSuperAdmin());
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $today = today();
        $days = collect(range(13, 0))->map(fn (int $back) => $today->copy()->subDays($back));
        $start = $today->copy()->subDays(13)->startOfDay();

        $borrowed = Loan::query()
            ->where('borrowed_at', '>=', $start)
            ->selectRaw('date(borrowed_at) as hari, count(*) as total')
            ->groupBy('hari')
            ->pluck('total', 'hari')
            ->all();

        $returned = Loan::query()
            ->where('returned_at', '>=', $start)
            ->selectRaw('date(returned_at) as hari, count(*) as total')
            ->groupBy('hari')
            ->pluck('total', 'hari')
            ->all();

        return [
            'datasets' => [
                [
                    'label' => 'Dipinjam',
                    'data' => $days->map(fn ($day) => (int) ($borrowed[$day->format('Y-m-d')] ?? 0))->all(),
                    'borderColor' => '#287A52',
                    'backgroundColor' => 'rgba(40, 122, 82, 0.12)',
                    'pointBackgroundColor' => '#1B5E40',
                    'fill' => true,
                    'tension' => 0.35,
                ],
                [
                    'label' => 'Dikembalikan',
                    'data' => $days->map(fn ($day) => (int) ($returned[$day->format('Y-m-d')] ?? 0))->all(),
                    'borderColor' => '#477FA5',
                    'backgroundColor' => 'rgba(71, 127, 165, 0.10)',
                    'pointBackgroundColor' => '#477FA5',
                    'fill' => true,
                    'tension' => 0.35,
                ],
            ],
            'labels' => $days->map(fn ($day) => $day->format('d M'))->all(),
        ];
    }
}
