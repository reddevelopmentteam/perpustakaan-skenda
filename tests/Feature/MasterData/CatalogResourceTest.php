<?php

namespace Tests\Feature\MasterData;

use App\Filament\Resources\Books\BookResource;
use App\Filament\Resources\Books\Pages\ListBooks;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_bisa_melihat_halaman_katalog(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff);

        $category = Category::factory()->create();
        $book = Book::factory()->create(['category_id' => $category->id]);

        $this->get(CategoryResource::getUrl('index'))->assertOk();
        $this->get(CategoryResource::getUrl('view', ['record' => $category]))->assertOk();
        $this->get(BookResource::getUrl('index'))->assertOk();
        $this->get(BookResource::getUrl('view', ['record' => $book]))->assertOk();
    }

    public function test_policy_kategori_dan_buku_terikat(): void
    {
        $staff = User::factory()->staff()->create();
        $student = User::factory()->student()->create();

        $category = Category::factory()->create();
        $book = Book::factory()->create();

        $this->assertTrue(Gate::forUser($staff)->allows('create', Category::class));
        $this->assertTrue(Gate::forUser($staff)->allows('update', $category));
        $this->assertTrue(Gate::forUser($staff)->allows('create', Book::class));
        $this->assertTrue(Gate::forUser($staff)->allows('update', $book));
        $this->assertTrue(Gate::forUser($staff)->allows('create', BookCopy::class));

        $this->assertFalse(Gate::forUser($student)->allows('create', Category::class));
        $this->assertFalse(Gate::forUser($student)->allows('update', $category));
        $this->assertFalse(Gate::forUser($student)->allows('create', Book::class));
        $this->assertFalse(Gate::forUser($student)->allows('create', BookCopy::class));
    }

    public function test_tabel_kategori_memuat_jumlah_buku_tanpa_n_plus_satu(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff);

        $categories = Category::factory()->count(3)->create();

        foreach ($categories as $category) {
            Book::factory()->count(2)->create(['category_id' => $category->id]);
        }

        DB::enableQueryLog();

        $records = CategoryResource::getEloquentQuery()->get();

        $queries = array_filter(
            DB::getQueryLog(),
            fn (array $log): bool => str_contains($log['query'], 'select')
        );

        $this->assertCount(3, $records);
        $this->assertSame(2, $records->first()->books_count);

        // Satu query utama + withCount: tidak boleh melebihi 2 query select.
        $this->assertLessThanOrEqual(2, count($queries));

        Livewire::test(ListCategories::class)
            ->assertOk()
            ->assertSee($categories->first()->name);
    }

    public function test_tabel_buku_memuat_relasi_dan_trashed_terkelola(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff);

        $book = Book::factory()->create();
        $trashed = Book::factory()->create();
        $trashed->delete();

        DB::enableQueryLog();

        $visible = BookResource::getEloquentQuery()->get();

        $queries = array_filter(
            DB::getQueryLog(),
            fn (array $log): bool => str_contains($log['query'], 'select')
        );

        // TrashedFilter butuh withTrashed: global SoftDeletingScope dilepas.
        $this->assertTrue($visible->contains('id', $trashed->id));
        $this->assertTrue($visible->first()->relationLoaded('category'));

        // Satu query utama + eager load kategori + withCount: wajar <= 3.
        $this->assertLessThanOrEqual(3, count($queries));

        Livewire::test(ListBooks::class)
            ->assertOk()
            ->assertSee($book->title);

        $this->get(BookResource::getUrl('index'))->assertOk();
    }
}
