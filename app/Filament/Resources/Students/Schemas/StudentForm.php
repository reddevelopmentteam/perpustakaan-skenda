<?php

namespace App\Filament\Resources\Students\Schemas;

use App\Enums\StudentStatus;
use App\Models\Student;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class StudentForm
{
    public static function classOptions(): array
    {
        return ['X-1' => 'X-1', 'X-2' => 'X-2', 'XI-1' => 'XI-1', 'XI-2' => 'XI-2', 'XII-1' => 'XII-1', 'XII-2' => 'XII-2'];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Akun Login')
                    ->relationship('user', 'name')
                    ->getOptionLabelFromRecordUsing(fn (User $record): string => "{$record->name} ({$record->email})")
                    ->getSearchResultsUsing(function (Select $component, ?string $search): array {
                        $record = $component->getRecord();
                        $takenIds = Student::query()
                            ->whereNotNull('user_id')
                            ->when($record?->exists, fn (Builder $query) => $query->where('id', '!=', $record->getKey()))
                            ->pluck('user_id')
                            ->all();

                        return User::query()
                            ->whereNotIn('id', $takenIds)
                            ->when(filled($search), fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (User $user): array => [$user->getKey() => "{$user->name} ({$user->email})"])
                            ->all();
                    })
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->unique(ignoreRecord: true)
                    ->helperText('Kosongkan bila belum punya akun.'),
                TextInput::make('student_number')
                    ->label('NIS')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(20)
                    ->validationMessages([
                        'required' => 'NIS wajib diisi.',
                        'unique' => 'NIS sudah terdaftar.',
                        'max' => 'NIS maksimal :max karakter.',
                    ]),
                TextInput::make('name')
                    ->label('Nama Lengkap')
                    ->required()
                    ->maxLength(255)
                    ->validationMessages([
                        'required' => 'Nama lengkap wajib diisi.',
                    ]),
                TextInput::make('class')
                    ->label('Kelas')
                    ->required()
                    ->datalist(self::classOptions())
                    ->maxLength(50)
                    ->validationMessages([
                        'required' => 'Kelas wajib diisi.',
                    ]),
                TextInput::make('major')
                    ->label('Jurusan')
                    ->nullable()
                    ->maxLength(100),
                TextInput::make('phone')
                    ->label('Telepon')
                    ->tel()
                    ->nullable()
                    ->maxLength(20)
                    ->validationMessages([
                        'max' => 'Telepon maksimal :max karakter.',
                    ]),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->nullable()
                    ->maxLength(255)
                    ->validationMessages([
                        'email' => 'Format email tidak valid.',
                    ]),
                Textarea::make('address')
                    ->label('Alamat')
                    ->nullable()
                    ->columnSpanFull(),
                Select::make('status')
                    ->label('Status')
                    ->options(collect(StudentStatus::cases())->mapWithKeys(fn (StudentStatus $status): array => [$status->value => $status->label()])->all())
                    ->default(StudentStatus::ACTIVE->value)
                    ->required()
                    ->validationMessages([
                        'required' => 'Status wajib dipilih.',
                    ]),
                DatePicker::make('joined_at')
                    ->label('Tanggal Bergabung')
                    ->default(now())
                    ->nullable(),
            ])
            ->columns(2);
    }
}
