<?php

namespace App\Filament\Pages;

use App\Enums\LoanItemStatus;
use App\Enums\LoanStatus;
use App\Exceptions\LoanException;
use App\Models\BookCopy;
use App\Models\Loan;
use App\Services\FineService;
use App\Services\ReturnService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

class ReturnBooks extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Transaksi';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Pengembalian';

    protected static ?string $title = 'Pengembalian';

    protected static ?string $slug = 'pengembalian';

    public ?array $data = [];

    public ?int $loanId = null;

    /** @var array<string, mixed> */
    public array $preview = [];

    /** @var array<int, array<string, mixed>> */
    public array $activeItems = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return Gate::allows('return', new Loan());
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return Gate::allows('return', new Loan());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('inventory_code')
                    ->label('Scan / Ketik Nomor Induk')
                    ->placeholder('cth. BK-000001')
                    ->helperText('Tempelkan kursor di sini lalu scan — tekan Cari atau Enter untuk mencari eksemplar.')
                    ->autofocus()
                    ->required()
                    ->validationMessages([
                        'required' => 'Nomor induk wajib diisi.',
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('return-scan-form')
                    ->livewireSubmitHandler('search')
                    ->footer([
                        Actions::make([
                            Action::make('search')
                                ->label('Cari')
                                ->icon('heroicon-m-magnifying-glass')
                                ->color('primary')
                                ->action('search'),
                        ])->key('return-search-actions'),
                    ]),

                Section::make('Hasil Pencarian')
                    ->compact()
                    ->visible(fn (): bool => filled($this->preview))
                    ->schema(function (): array {
                        $components = [
                            Text::make($this->previewTitle())->weight('bold')->size('lg'),
                            Text::make($this->previewSubtitle()),
                        ];

                        if (($this->preview['status'] ?? '') === 'found') {
                            $components[] = Text::make($this->previewDetail());
                        }

                        if (($this->preview['status'] ?? '') === 'error') {
                            $components[] = Text::make($this->preview['message'] ?? 'Barcode tidak dikenal.')
                                ->color('danger')
                                ->weight('bold');
                        }

                        if (($this->preview['status'] ?? '') === 'warning') {
                            $components[] = Text::make($this->preview['message'] ?? 'Eksemplar tidak sedang dipinjam.')
                                ->color('warning')
                                ->weight('bold');
                        }

                        return $components;
                    }),

                Section::make('Buku dalam Pinjaman Ini')
                    ->compact()
                    ->visible(fn (): bool => ($this->preview['status'] ?? '') === 'found' && filled($this->activeItems))
                    ->schema(function (): array {
                        $rows = [];

                        foreach ($this->activeItems as $item) {
                            $rows[] = Text::make(($item['book_title'] ?? '-') . ' — ' . ($item['inventory_code'] ?? '-'));
                        }

                        return $rows !== [] ? $rows : [Text::make('Tidak ada item aktif.')];
                    })
                    ->footer([
                        Actions::make([
                            Action::make('processReturn')
                                ->label(fn (): string => 'Proses Pengembalian (' . count($this->activeItems) . ' buku)')
                                ->icon('heroicon-m-arrow-uturn-left')
                                ->color('success')
                                ->requiresConfirmation()
                                ->modalHeading('Proses pengembalian')
                                ->modalDescription(fn (): string => 'Kembalikan SEMUA ' . count($this->activeItems) . ' buku aktif pada pinjaman ' . ($this->preview['loan_number'] ?? '') . ' sekaligus?')
                                ->visible(fn (): bool => ($this->preview['status'] ?? '') === 'found' && filled($this->activeItems))
                                ->action('processReturn'),
                        ])->key('return-process-actions'),
                    ]),
            ]);
    }

    public function search(): void
    {
        $state = $this->form->getState();
        $inventoryCode = trim((string) ($state['inventory_code'] ?? ''));

        $this->reset(['loanId', 'preview', 'activeItems']);

        if ($inventoryCode === '') {
            Notification::make()
                ->danger()
                ->title('Nomor induk wajib diisi.')
                ->send();

            return;
        }

        $copy = BookCopy::query()->where('inventory_code', $inventoryCode)->first();

        if (! $copy) {
            $this->preview = [
                'status' => 'error',
                'message' => "Nomor induk {$inventoryCode} tidak dikenal. Periksa kembali nomor induk eksemplar.",
            ];

            Notification::make()
                ->danger()
                ->title('Nomor induk tidak dikenal.')
                ->body("Tidak ada eksemplar dengan nomor induk {$inventoryCode}.")
                ->send();

            return;
        }

        $activeItem = \App\Models\LoanItem::query()
            ->with(['loan.student', 'book', 'copy'])
            ->where('book_copy_id', $copy->id)
            ->whereIn('status', [LoanItemStatus::BORROWED, LoanItemStatus::OVERDUE])
            ->latest('id')
            ->first();

        if (! $activeItem || ! $activeItem->loan || ! $activeItem->loan->status->isActive()) {
            $lastLoan = \App\Models\LoanItem::query()
                ->with(['loan.student', 'book'])
                ->where('book_copy_id', $copy->id)
                ->latest('id')
                ->first();

            $info = $lastLoan?->loan
                ? ' Terakhir tercatat pada pinjaman ' . $lastLoan->loan->loan_number . ' (' . $lastLoan->loan->status->label() . ').'
                : '';

            $this->preview = [
                'status' => 'warning',
                'message' => "Eksemplar {$inventoryCode} tidak sedang dipinjam." . $info,
            ];

            Notification::make()
                ->warning()
                ->title('Eksemplar tidak sedang dipinjam.')
                ->body("Eksemplar {$inventoryCode} tidak tercatat dipinjam pada transaksi aktif." . $info)
                ->send();

            return;
        }

        $loan = $activeItem->loan;
        $loan->loadMissing(['student', 'items.book', 'items.copy']);

        $this->loanId = $loan->id;

        try {
            $calc = \App\Services\FineService::calculate($loan);
        } catch (\Throwable) {
            $calc = ['late_days' => 0, 'amount' => 0, 'rate' => 0];
        }

        $this->activeItems = $loan->items
            ->filter(fn ($item): bool => in_array($item->status, [LoanItemStatus::BORROWED, LoanItemStatus::OVERDUE], true))
            ->map(fn ($item): array => [
                'id' => $item->id,
                'book_title' => $item->book?->title ?? 'Buku #' . $item->book_id,
                'inventory_code' => $item->copy?->inventory_code ?? '-',
                'due_at' => $item->due_at?->format('d M Y') ?? $loan->due_at?->format('d M Y') ?? '-',
                'status' => $item->status->label(),
            ])
            ->values()
            ->all();

        $this->preview = [
            'status' => 'found',
            'loan_number' => $loan->loan_number,
            'student' => $loan->student?->name ?? '-',
            'loan_id' => $loan->id,
            'late_days' => (int) ($calc['late_days'] ?? 0),
            'amount' => (int) ($calc['amount'] ?? 0),
            'rate' => (int) ($calc['rate'] ?? 0),
        ];
    }

    public function processReturn(): void
    {
        $loan = $this->loanId ? Loan::query()->with(['items'])->find($this->loanId) : null;

        if (! $loan) {
            Notification::make()
                ->danger()
                ->title('Tidak ada pinjaman yang dipilih.')
                ->body('Cari nomor induk terlebih dahulu sebelum memproses pengembalian.')
                ->send();

            return;
        }

        if (! Gate::allows('return', $loan)) {
            Notification::make()
                ->danger()
                ->title('Anda tidak berwenang memproses pengembalian.')
                ->send();

            return;
        }

        $copyIds = $loan->items()
            ->whereIn('status', [LoanItemStatus::BORROWED, LoanItemStatus::OVERDUE])
            ->whereNotNull('book_copy_id')
            ->pluck('book_copy_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($copyIds === []) {
            Notification::make()
                ->warning()
                ->title('Tidak ada buku aktif untuk dikembalikan.')
                ->send();

            return;
        }
        try {
            $loan = \App\Services\ReturnService::process($loan->fresh(), auth()->user(), $copyIds);
        } catch (LoanException $e) {
            Notification::make()
                ->danger()
                ->title('Gagal memproses pengembalian.')
                ->body($e->getMessage())
                ->send();

            return;
        }
        $loanNumber = $loan->loan_number;
        $fine = $loan->fines()->latest('id')->first();

        $this->form->fill();
        $this->reset(['loanId', 'preview', 'activeItems']);

        $title = "Pengembalian {$loanNumber} berhasil diproses.";

        if ($fine && $fine->status === \App\Enums\FineStatus::UNPAID && (int) $fine->amount > 0) {
            $title .= ' Denda Rp ' . number_format((int) $fine->amount, 0, ',', '.') . ' (' . (int) $fine->late_days . ' hari terlambat) — silakan bayar di menu Keuangan > Denda.';
        }

        Notification::make()
            ->success()
            ->title($title)
            ->send();
    }


    private function previewTitle(): string
    {
        return match ($this->preview['status'] ?? '') {
            'found' => 'Pinjaman ' . ($this->preview['loan_number'] ?? ''),
            'error' => 'Nomor induk tidak dikenal',
            'warning' => 'Tidak sedang dipinjam',
            default => '',
        };
    }

    private function previewSubtitle(): string
    {
        if (($this->preview['status'] ?? '') !== 'found') {
            return '';
        }

        $late = (int) ($this->preview['late_days'] ?? 0);
        $lateText = $late > 0 ? "Terlambat {$late} hari." : 'Belum terlambat.';

        return 'Siswa: ' . ($this->preview['student'] ?? '-') . '. ' . $lateText;
    }

    private function previewDetail(): string
    {
        $amount = (int) ($this->preview['amount'] ?? 0);
        $rate = (int) ($this->preview['rate'] ?? 0);
        $late = (int) ($this->preview['late_days'] ?? 0);

        if ($late <= 0 || $amount <= 0) {
            return 'Perkiraan denda: Rp 0 (tepat waktu).';
        }

        return 'Perkiraan denda: Rp ' . number_format($amount, 0, ',', '.')
            . " ({$late} hari × Rp " . number_format($rate, 0, ',', '.') . '/hari, pra-simpan).';
    }

    /**
     * @return array<string>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }
}
