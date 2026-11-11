<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Book>
 */
class BookFactory extends Factory
{
    protected $model = Book::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'isbn' => $this->faker->unique()->numerify('#############'),
            'title' => $this->faker->sentence(3),
            'author' => $this->faker->name(),
            'publisher' => $this->faker->company(),
            'publication_year' => $this->faker->numberBetween(1990, 2026),
            'category_id' => Category::factory(),
            'stock' => 3,
            'available_stock' => 3,
            'shelf_location' => 'Rak-A-'.$this->faker->numberBetween(1, 5),
            'description' => $this->faker->paragraph(),
        ];
    }
}
