# Sistem Informasi Perpustakaan Sekolah (perpustakaan-skenda)

Aplikasi web untuk manajemen perpustakaan sekolah berbasis **Laravel 13**, **Filament v5**, **Livewire v4**, dan **SQLite** (dev). Sistem siap digunakan untuk operasional perpustakaan sekolah dengan fitur peminjaman, pengembalian, reservasi, denda, notifikasi, dan laporan.

## Fitur Utama

- **Manajemen Buku**: CRUD buku, kategori, dan eksemplar fisik (book_copies) dengan barcode unik per eksemplar
- **Manajemen Anggota**: CRUD siswa/anggota dengan status aktif/nonaktif/diblokir
- **Peminjaman**: Workflow lengkap (ajuan → persetujuan petugas → peminjaman fisik) dengan anti-race condition pada stok
- **Pengembalian**: Proses pengembalian dengan perhitungan denda otomatis, idempotent terhadap double-submit
- **Perpanjangan**: Pengajuan perpanjangan dengan validasi antrean reservasi dan batas maksimal
- **Reservasi**: Antrean reservasi buku yang tidak tersedia, promosi otomatis saat buku dikembalikan
- **Denda**: Perhitungan denda otomatis berdasarkan keterlambatan, status lunas/belum dibayar/dicabut
- **Notifikasi**: Reminder H-3/H-1/H0 dan notifikasi keterlambatan melalui scheduler
- **Laporan**: Laporan peminjaman, pengembalian, keterlambatan, buku, anggota, dan denda dengan export CSV
- **Pengaturan**: Konfigurasi sistem (durasi pinjam, denda per hari, batas pinjaman, dll.) dengan caching
- **Audit Log**: Catatan semua tindakan penting untuk pelacakan aktivitas
- **Role & Authorization**: Super Admin, Petugas, Siswa dengan policy-based authorization

## Struktur Role

| Role | Email | Password | Keterangan |
|------|-------|----------|------------|
| Super Admin | superadmin@skenda.test | password | Akses penuh + pengaturan |
| Petugas | staff@skenda.test | password | Operasional harian |
| Siswa | siswa1@skenda.test | password | NIS 2526001 |
| Siswa | siswa2@skenda.test | password | NIS 2526002 |
| Siswa | siswa3@skenda.test | password | NIS 2526003 |

## Persyaratan Sistem

- PHP `^8.3`
- Composer
- Node.js & npm (untuk asset build)
- SQLite (development) / MySQL / PostgreSQL (production)

## Instalasi

```bash
# Clone repository
git clone <repository-url>
cd perpustakaan-skenda

# Install dependencies PHP
composer install

# Install dependencies JS
npm install

# Salin file environment
cp .env.example .env
php artisan key:generate

# Konfigurasi database di .env (default SQLite)
# DB_CONNECTION=sqlite
# DB_DATABASE=/absolute/path/to/database/database.sqlite

# Buat file database SQLite (jika belum ada)
touch database/database.sqlite

# Jalankan migrasi dan seeder
php artisan migrate:fresh --seed

# Build asset frontend
npm run build

# Jalankan development server
php artisan serve
```

## Database Setup

Migrasi mencakup semua tabel inti:

- `users` — akun login dengan role (student/staff/super_admin)
- `students` — data siswa/anggota (terkait user)
- `categories` — kategori buku
- `books` — metadata buku (judul, penulis, ISBN, stok, dll.)
- `book_copies` — eksemplar fisik dengan barcode unik
- `loans` — transaksi peminjaman
- `loan_items` — detail item dalam peminjaman
- `loan_extensions` — riwayat perpanjangan
- `reservations` — antrean reservasi buku
- `fines` — denda keterlambatan
- `settings` — konfigurasi sistem
- `audit_logs` — catatan aktivitas
- `notifications` — notifikasi sistem

## Seeder

```bash
php artisan migrate:fresh --seed
```

Seeder membuat data development: super admin, petugas, 3 siswa, kategori, buku dengan eksemplar, dan data transaksi contoh.

## Queue

Notifikasi diantrekan (ShouldQueue). Untuk menjalankan worker:

```bash
php artisan queue:work
```

## Scheduler

Scheduler menjalankan tugas berkala:

- `loans:mark-overdue` (setiap jam) — tandai peminjaman terlambat
- `reservations:expire` (setiap jam) — kedaluwarsakan reservasi dan oper antrean
- `loans:send-reminders` (setiap hari pukul 07:00) — kirim reminder H-3/H-1/H0

Tambahkan ke crontab server:

```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

Untuk development, jalankan manual:

```bash
php artisan schedule:run
```

## Storage Link

```bash
php artisan storage:link
```

Untuk mengakses file yang diunggah (cover buku, logo pengaturan, dll.) dari `public/storage`.

## Credential Development

Semua akun development menggunakan password `password`. Jangan gunakan kredensial ini di production.

## Role Pengguna

### Super Admin
- Akses penuh ke semua fitur
- Mengelola akun pengguna, role, dan pengaturan sistem
- Mengakses semua laporan dan audit log

### Petugas
- Mengelola data buku, kategori, anggota
- Menyetujui/menolak peminjaman
- Memproses pengembalian dan perpanjangan
- Mengelola denda dan reservasi

### Siswa
- Melihat katalog buku dan ketersediaan
- Mengajukan peminjaman dan reservasi
- Melihat riwayat peminjaman dan denda
- Mengakses dashboard pribadi

## Alur Peminjaman

1. Siswa login dan mencari buku di katalog
2. Siswa mengajukan peminjaman (status: PENDING)
3. Petugas menyetujui atau menolak pengajuan
4. Jika disetujui, petugas menandai buku sebagai dipinjam (status: BORROWED, stok berkurang)
5. Sistem menentukan tanggal jatuh tempo berdasarkan pengaturan

## Alur Pengembalian

1. Petugas membuka menu pengembalian dan scan barcode
2. Sistem menampilkan detail peminjaman dan denda (jika terlambat)
3. Petugas mengonfirmasi pengembalian (stok kembali, status diperbarui)
4. Sistem menghitung denda otomatis jika terlambat

## Alur Reservasi

1. Siswa membuat reservasi untuk buku yang tidak tersedia
2. Reservasi masuk antrean berdasarkan queue_position
3. Ketika buku dikembalikan, sistem mempromosikan reservasi terdepan menjadi AVAILABLE
4. Siswa diberi notifikasi dan mempunyai waktu tertentu untuk mengambil buku
5. Jika tidak mengambil hingga batas waktu, reservasi expired dan antrean berikutnya dipromosikan

## Command Penting

| Command | Keterangan |
|---------|------------|
| `php artisan migrate:fresh --seed` | Reset database + seed development |
| `php artisan test` | Jalankan seluruh test suite |
| `php artisan schedule:run` | Jalankan scheduler tugas berkala |
| `php artisan queue:work` | Jalankan worker queue |
| `php artisan storage:link` | Buat symlink storage publik |
| `npm run build` | Build asset frontend (produksi) |
| `npm run dev` | Watch asset frontend (development) |

## Testing

Test suite mencakup 14 kasus bisnis kritis per §46 perpus.md:

1. Siswa dapat membuat pengajuan peminjaman
2. Siswa tidak dapat meminjam stok kosong
3. Siswa tidak dapat melebihi limit pinjaman
4. Petugas dapat approve peminjaman
5. Stok berkurang hanya pada tahap BORROWED
6. Pengembalian meningkatkan stok
7. Double return tidak meningkatkan stok dua kali
8. Denda dihitung dengan benar
9. Reservasi masuk antrean
10. Buku yang kembali diberikan ke reservasi pertama
11. Reservasi expired dipindahkan ke antrean berikutnya
12. Siswa tidak dapat melihat transaksi siswa lain
13. Petugas tidak dapat mengakses fitur Super Admin tanpa permission
14. Race condition stok tidak menyebabkan over-borrowing

Jalankan test:

```bash
php artisan test
```

## Arsitektur

- **Service Layer**: Bisnis logic terpusat di service class (`LoanService`, `ReturnService`, `FineService`, `ReservationService`, `NotificationService`, `SettingsService`, `BookStockService`, `BarcodeService`)
- **Events & Listeners**: Domain events untuk audit dan notifikasi terpisah dari transaksi inti
- **Policies**: Authorization berbasis policy untuk setiap model
- **Enum**: PHP BackedEnum dengan `label()` dalam Bahasa Indonesia
- **Soft Deletes**: `books`, `book_copies`, `students` menggunakan soft delete; `loans`/`loan_items` tidak dihapus

## Dokumentasi Lengkap

Lihat file `perpus.md` (53 bab) sebagai sumber kebenaran bisnis dan spesifikasi implementasi.

## Lisensi

Internal — Sistem Informasi Perpustakaan Sekolah.
