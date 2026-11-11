<?php

namespace App\Filament\Pages\Settings;

use App\Models\AuditLog;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Pengaturan sistem (§26): form tunggal tanpa tabel,
 * super-admin only. Perubahan diaudit satu baris `settings.updated`.
 */
class ManageSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?int $navigationSort = 100;

    protected static ?string $navigationLabel = 'Pengaturan';

    protected static ?string $title = 'Pengaturan';

    protected static ?string $slug = 'pengaturan';

    public ?array $data = [];

    /** @var array<string, string> */
    private static function defaults(): array
    {
        return [
            'library_name' => 'Perpustakaan Sekolah',
            'school_name' => 'SMA Negeri Skenda',
            'school_address' => '',
            'school_phone' => '',
            'library_logo' => '',
            'loan_duration_days' => '7',
            'max_loans_per_student' => '3',
            'max_extensions' => '1',
            'extension_days' => '7',
            'fine_per_day' => '1000',
            'grace_period_days' => '0',
            'max_fine' => '',
            'reservation_hold_days' => '3',
            'reminder_days' => '3,1,0',
        ];
    }

    public function mount(): void
    {
        $values = [];

        foreach (self::defaults() as $key => $default) {
            $values[$key] = Setting::get($key, $default);
        }

        $this->form->fill($values);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Identitas')
                    ->schema([
                        TextInput::make('library_name')
                            ->label('Nama Perpustakaan')
                            ->required()
                            ->maxLength(255)
                            ->validationMessages(['required' => 'Nama perpustakaan wajib diisi.']),
                        TextInput::make('school_name')
                            ->label('Nama Sekolah')
                            ->required()
                            ->maxLength(255)
                            ->validationMessages(['required' => 'Nama sekolah wajib diisi.']),
                        Textarea::make('school_address')
                            ->label('Alamat Sekolah')
                            ->nullable()
                            ->maxLength(500)
                            ->columnSpanFull(),
                        TextInput::make('school_phone')
                            ->label('Telepon')
                            ->tel()
                            ->nullable()
                            ->maxLength(20),
                        FileUpload::make('library_logo')
                            ->label('Logo')
                            ->disk('public')
                            ->directory('settings')
                            ->image()
                            ->maxSize(2048)
                            ->nullable()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Aturan Peminjaman')
                    ->schema([
                        TextInput::make('loan_duration_days')
                            ->label('Durasi Pinjam (hari)')
                            ->required()
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(30)
                            ->validationMessages([
                                'required' => 'Durasi pinjam wajib diisi.',
                                'integer' => 'Durasi pinjam harus bilangan bulat.',
                                'min' => 'Durasi pinjam minimal :min hari.',
                                'max' => 'Durasi pinjam maksimal :max hari.',
                            ]),
                        TextInput::make('max_loans_per_student')
                            ->label('Maksimal Pinjaman per Siswa')
                            ->required()
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(10)
                            ->validationMessages([
                                'required' => 'Maksimal pinjaman wajib diisi.',
                                'integer' => 'Maksimal pinjaman harus bilangan bulat.',
                                'min' => 'Maksimal pinjaman minimal :min.',
                                'max' => 'Maksimal pinjaman maksimal :max.',
                            ]),
                        TextInput::make('max_extensions')
                            ->label('Maksimal Perpanjangan')
                            ->required()
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(5)
                            ->validationMessages([
                                'required' => 'Maksimal perpanjangan wajib diisi.',
                                'integer' => 'Maksimal perpanjangan harus bilangan bulat.',
                                'min' => 'Maksimal perpanjangan minimal :min.',
                                'max' => 'Maksimal perpanjangan maksimal :max.',
                            ]),
                        TextInput::make('extension_days')
                            ->label('Durasi Perpanjangan (hari)')
                            ->required()
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(30)
                            ->validationMessages([
                                'required' => 'Durasi perpanjangan wajib diisi.',
                                'integer' => 'Durasi perpanjangan harus bilangan bulat.',
                                'min' => 'Durasi perpanjangan minimal :min hari.',
                                'max' => 'Durasi perpanjangan maksimal :max hari.',
                            ]),
                    ])
                    ->columns(2),
                Section::make('Denda & Reservasi')
                    ->schema([
                        TextInput::make('fine_per_day')
                            ->label('Denda per Hari (Rp)')
                            ->required()
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->validationMessages([
                                'required' => 'Nominal denda wajib diisi.',
                                'integer' => 'Nominal denda harus bilangan bulat.',
                                'min' => 'Nominal denda tidak boleh negatif.',
                            ]),
                        TextInput::make('grace_period_days')
                            ->label('Tenggang Waktu (hari)')
                            ->required()
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(30)
                            ->validationMessages([
                                'required' => 'Tenggang waktu wajib diisi.',
                                'integer' => 'Tenggang waktu harus bilangan bulat.',
                                'min' => 'Tenggang waktu tidak boleh negatif.',
                                'max' => 'Tenggang waktu maksimal :max hari.',
                            ]),
                        TextInput::make('max_fine')
                            ->label('Denda Maksimal (Rp)')
                            ->nullable()
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->helperText('Kosongkan = tanpa batas.')
                            ->validationMessages([
                                'integer' => 'Denda maksimal harus bilangan bulat.',
                                'min' => 'Denda maksimal tidak boleh negatif.',
                            ]),
                        TextInput::make('reservation_hold_days')
                            ->label('Masa Tahan Reservasi (hari)')
                            ->required()
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(14)
                            ->validationMessages([
                                'required' => 'Masa tahan reservasi wajib diisi.',
                                'integer' => 'Masa tahan reservasi harus bilangan bulat.',
                                'min' => 'Masa tahan reservasi minimal :min hari.',
                                'max' => 'Masa tahan reservasi maksimal :max hari.',
                            ]),
                        TextInput::make('reminder_days')
                            ->label('Pengingat (H-berapa)')
                            ->required()
                            ->maxLength(50)
                            ->helperText('H-3,H-1,H0 — koma-pisah.')
                            ->regex('/^\d+(,\d+)*$/')
                            ->default('3,1,0')
                            ->validationMessages([
                                'required' => 'Jadwal pengingat wajib diisi.',
                                'regex' => 'Format pengingat tidak valid, contoh: 3,1,0.',
                            ]),
                    ])
                    ->columns(2),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Simpan Pengaturan')
                ->color('primary')
                ->action('save'),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $newValues = [];

        foreach (array_keys(self::defaults()) as $key) {
            $raw = $state[$key] ?? null;
            $value = $raw === null || $raw === '' ? null : (string) $raw;

            // max_fine kosong = tanpa batas (tidak ada baris setting).
            if ($key === 'max_fine' && ($value === null || $value === '')) {
                Setting::where('key', $key)->delete();
                $newValues[$key] = null;

                continue;
            }

            Setting::set($key, $value);
            $newValues[$key] = $value;
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'settings.updated',
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        Notification::make()
            ->title('Pengaturan berhasil disimpan.')
            ->success()
            ->send();
    }

    /**
     * @return array<string>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }
}
