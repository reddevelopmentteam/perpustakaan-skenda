<?php

namespace Database\Factories;

use App\Enums\BookCopyStatus;
use App\Models\Book;
use App\Models\BookCopy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookCopy>
 */
class BookCopyFactory extends Factory
{
    protected $model = BookCopy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'book_id' => Book::factory(),
            'inventory_code' => $this->faker->unique()->numerify('BK-######'),
            'status' => BookCopyStatus::AVAILABLE,
            'condition' => $this->faker->randomElement(['Baik', 'Baik', 'Baik', 'Sampul kusam']),
        ];
    }
}
