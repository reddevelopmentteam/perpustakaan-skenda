<?php

namespace App\Filament\Widgets;

use App\Models\Book;
use App\Models\Category;
use Filament\Widgets\ChartWidget;

class CategoryChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'Koleksi per Kategori';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isStaff() || $user->isSuperAdmin());
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $top = Category::withCount('books')
            ->orderByDesc('books_count')
            ->limit(6)
            ->get();

        $labels = $top->pluck('name')->all();
        $data = $top->pluck('books_count')->map(fn ($count) => (int) $count)->all();

        $uncategorized = Book::whereNull('category_id')->count();

        if ($uncategorized > 0) {
            $labels[] = 'Tanpa Kategori';
            $data[] = $uncategorized;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Jumlah Buku',
                    'data' => $data,
                    'backgroundColor' => [
                        '#1B5E40',
                        '#287A52',
                        '#3D996B',
                        '#6BB58C',
                        '#9ED4B3',
                        '#477FA5',
                        '#DCEFE3',
                    ],
                    'borderColor' => '#FFFFFF',
                    'borderWidth' => 2,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
