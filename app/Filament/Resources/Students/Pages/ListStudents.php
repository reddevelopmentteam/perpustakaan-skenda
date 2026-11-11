<?php

namespace App\Filament\Resources\Students\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Students\StudentResource;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ListStudents extends ListRecords
{
    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('import')
                ->label('Impor Anggota')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->modal()
                ->form([
                    FileUpload::make('csv_file')
                        ->label('File CSV')
                        ->acceptedFileTypes(['text/csv', 'application/csv', 'text/plain'])
                        ->required()
                        ->helperText('Header: student_number, name, class, major, phone, email, address, status'),
                ])
                ->action(function (array $data): void {
                    $file = $data['csv_file'];
                    $path = $file->getRealPath();

                    if (($handle = fopen($path, 'r')) === false) {
                        Notification::make()
                            ->danger()
                            ->title('Gagal membuka file CSV.')
                            ->send();

                        return;
                    }

                    $header = fgetcsv($handle);
                    if ($header === false) {
                        fclose($handle);
                        Notification::make()
                            ->danger()
                            ->title('File CSV kosong atau tidak valid.')
                            ->send();

                        return;
                    }

                    $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
                    $rows = [];

                    while (($row = fgetcsv($handle)) !== false) {
                        $rows[] = array_combine($header, array_map(fn ($v) => trim((string) $v), $row));
                    }

                    fclose($handle);

                    $imported = 0;
                    $invalid = 0;
                    $line = 2;

                    foreach ($rows as $row) {
                        $errors = [];
                        $studentNumber = $row['student_number'] ?? $row['nis'] ?? '';
                        $name = $row['name'] ?? $row['nama'] ?? '';
                        $class = $row['class'] ?? $row['kelas'] ?? '';

                        if ($studentNumber === '') {
                            $errors[] = 'NIS wajib diisi.';
                        } elseif (Student::query()->where('student_number', $studentNumber)->exists()) {
                            $errors[] = "NIS \"{$studentNumber}\" sudah terdaftar.";
                        }

                        if ($name === '') {
                            $errors[] = 'Nama wajib diisi.';
                        }

                        $email = $row['email'] ?? null;
                        if ($email !== null && $email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $errors[] = 'Format email tidak valid.';
                        }

                        $status = strtolower($row['status'] ?? 'active');
                        if (! in_array($status, ['active', 'inactive', 'blocked'], true)) {
                            $status = 'active';
                        }

                        if ($errors !== []) {
                            $invalid++;
                            $line++;
                            continue;
                        }

                        try {
                            DB::transaction(function () use ($studentNumber, $name, $class, $row, $status, $email): void {
                                $student = Student::create([
                                    'student_number' => $studentNumber,
                                    'name' => $name,
                                    'class' => $class,
                                    'major' => $row['major'] ?? $row['jurusan'] ?? null,
                                    'phone' => $row['phone'] ?? $row['telepon'] ?? null,
                                    'email' => $email ?? null,
                                    'address' => $row['address'] ?? $row['alamat'] ?? null,
                                    'status' => $status,
                                    'joined_at' => now(),
                                ]);

                                $userEmail = $email ?? $studentNumber . '@skenda.test';
                                $userExists = User::query()->where('email', $userEmail)->first();
                                if (! $userExists) {
                                    $password = Hash::make('password');
                                    User::create([
                                        'name' => $name,
                                        'email' => $userEmail,
                                        'username' => $studentNumber,
                                        'password' => $password,
                                        'role' => UserRole::STUDENT,
                                        'is_active' => $status === 'active',
                                    ]);
                                }

                                $createdUser = User::query()->where('email', $userEmail)->first();
                                $student->user_id = $createdUser->id;
                                $student->save();
                            });

                            $imported++;
                        } catch (\Throwable $e) {
                            $invalid++;
                        }

                        $line++;
                    }

                    Notification::make()
                        ->title("Impor selesai: {$imported} berhasil, {$invalid} gagal")
                        ->success()
                        ->send();
                }),
        ];
    }
}
