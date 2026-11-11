<?php

namespace Database\Seeders;

use App\Enums\BookCopyStatus;
use App\Enums\LoanItemStatus;
use App\Enums\LoanStatus;
use App\Enums\ReservationStatus;
use App\Enums\StudentStatus;
use App\Enums\UserRole;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Category;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LibrarySeeder extends Seeder
{
    /**
     * Seed data awal sistem informasi perpustakaan sekolah.
     */
    public function run(): void
    {
        $now = now();
        $dateStamp = $now->format('Ymd');
        $joinedAt = $now->month >= 7
            ? $now->copy()->month(7)->day(1)
            : $now->copy()->subYear()->month(7)->day(1);

        // (a) Pengaturan sistem. max_fine sengaja tidak dibuatkan baris
        // (null direpresentasikan sebagai tidak adanya baris).
        $settings = [
            'loan_duration_days' => '7',
            'max_loans_per_student' => '3',
            'max_extensions' => '1',
            'extension_days' => '7',
            'fine_per_day' => '1000',
            'grace_period_days' => '0',
            'reservation_hold_days' => '3',
        ];

        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        // (b) Akun pengguna.
        User::create([
            'name' => 'Amelia Admin',
            'username' => 'superadmin',
            'email' => 'superadmin@skenda.test',
            'password' => Hash::make('password'),
            'role' => UserRole::SUPER_ADMIN,
            'is_active' => true,
        ]);

        $staff = User::create([
            'name' => 'Budi Pustaka',
            'username' => 'petugas',
            'email' => 'staff@skenda.test',
            'password' => Hash::make('password'),
            'role' => UserRole::STAFF,
            'is_active' => true,
        ]);

        $siswaUsers = [];
        foreach ([
            ['Dewi Lestari', 'siswa1', 'siswa1@skenda.test'],
            ['Rizky Pratama', 'siswa2', 'siswa2@skenda.test'],
            ['Sinta Maharani', 'siswa3', 'siswa3@skenda.test'],
        ] as [$name, $username, $email]) {
            $siswaUsers[] = User::create([
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'password' => Hash::make('password'),
                'role' => UserRole::STUDENT,
                'is_active' => true,
            ]);
        }
        [$siswa1User, $siswa2User, $siswa3User] = $siswaUsers;

        // (c) Data siswa untuk ketiga akun siswa.
        $student1 = Student::create([
            'user_id' => $siswa1User->id,
            'student_number' => '2526001',
            'name' => $siswa1User->name,
            'class' => 'X-1',
            'major' => 'IPA',
            'phone' => '081234500001',
            'email' => $siswa1User->email,
            'address' => 'Jl. Merdeka No. 1, Surakarta',
            'status' => StudentStatus::ACTIVE,
            'joined_at' => $joinedAt,
        ]);

        $student2 = Student::create([
            'user_id' => $siswa2User->id,
            'student_number' => '2526002',
            'name' => $siswa2User->name,
            'class' => 'XI-2',
            'major' => 'TKJ',
            'phone' => '081234500002',
            'email' => $siswa2User->email,
            'address' => 'Jl. Sudirman No. 45, Surakarta',
            'status' => StudentStatus::ACTIVE,
            'joined_at' => $joinedAt,
        ]);

        $student3 = Student::create([
            'user_id' => $siswa3User->id,
            'student_number' => '2526003',
            'name' => $siswa3User->name,
            'class' => 'XII-1',
            'major' => 'IPS',
            'phone' => '081234500003',
            'email' => $siswa3User->email,
            'address' => 'Jl. Ahmad Yani No. 12, Surakarta',
            'status' => StudentStatus::ACTIVE,
            'joined_at' => $joinedAt,
        ]);

        // (d) 6 kategori + 12 buku (2 per kategori), masing-masing 3 eksemplar.
        $categories = collect([
            ['Fiksi', 'Koleksi karya fiksi dan sastra untuk bacaan umum.'],
            ['Sains', 'Koleksi buku ilmu pengetahuan alam dan eksperimen.'],
            ['Matematika', 'Koleksi buku matematika dari dasar hingga lanjutan.'],
            ['Sejarah', 'Koleksi buku sejarah Indonesia dan dunia.'],
            ['Teknologi', 'Koleksi buku teknologi, komputer, dan informatika.'],
            ['Bahasa', 'Koleksi buku bahasa Indonesia, Inggris, dan daerah.'],
        ])->map(fn (array $row) => Category::create([
            'name' => $row[0],
            'slug' => Str::slug($row[0]),
            'description' => $row[1],
        ]));

        $books = collect();
        foreach ($categories as $category) {
            for ($i = 0; $i < 2; $i++) {
                $book = Book::factory()->create(['category_id' => $category->id]);
                BookCopy::factory()->count(3)->create(['book_id' => $book->id]);
                $books->push($book);
            }
        }

        $firstBook = $books->first();
        $secondBook = $books->get(1);

        // (e) 1 peminjaman BORROWED milik siswa1.
        $borrowedAt = $now->copy()->subWeek();
        $dueAt = $borrowedAt->copy()->addDays(7);

        $loan1 = Loan::create([
            'loan_number' => 'PJ-'.$dateStamp.'-0001',
            'student_id' => $student1->id,
            'status' => LoanStatus::BORROWED,
            'requested_at' => $borrowedAt,
            'approved_at' => $borrowedAt,
            'borrowed_at' => $borrowedAt,
            'due_at' => $dueAt,
            'approved_by' => $staff->id,
            'extension_count' => 0,
        ]);

        $borrowedCopy = $firstBook->copies()->orderBy('id')->first();

        LoanItem::create([
            'loan_id' => $loan1->id,
            'book_id' => $firstBook->id,
            'book_copy_id' => $borrowedCopy->id,
            'quantity' => 1,
            'returned_quantity' => 0,
            'due_at' => $dueAt,
            'status' => LoanItemStatus::BORROWED,
        ]);

        $borrowedCopy->update(['status' => BookCopyStatus::BORROWED]);
        $firstBook->decrement('available_stock');

        // (f) 1 peminjaman PENDING milik siswa2 (stok tidak berubah).
        $loan2 = Loan::create([
            'loan_number' => 'PJ-'.$dateStamp.'-0002',
            'student_id' => $student2->id,
            'status' => LoanStatus::PENDING,
            'requested_at' => $now,
            'extension_count' => 0,
        ]);

        LoanItem::create([
            'loan_id' => $loan2->id,
            'book_id' => $secondBook->id,
            'book_copy_id' => null,
            'quantity' => 1,
            'returned_quantity' => 0,
            'due_at' => null,
            'status' => LoanItemStatus::PENDING,
        ]);

        // (g) 1 reservasi WAITING milik siswa3 atas buku pertama.
        Reservation::create([
            'reservation_number' => 'RSV-'.$dateStamp.'-0001',
            'student_id' => $student3->id,
            'book_id' => $firstBook->id,
            'status' => ReservationStatus::WAITING,
            'reserved_at' => $now,
            'expires_at' => $now->copy()->addDays(3),
            'queue_position' => 1,
        ]);
    }
}
