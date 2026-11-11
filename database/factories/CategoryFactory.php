<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $pool = [
            'Fiksi',
            'Sains',
            'Matematika',
            'Sejarah',
            'Teknologi',
            'Bahasa',
            'Agama',
            'Komik Edukasi',
        ];

        try {
            $name = $this->faker->unique()->randomElement($pool);
        } catch (\OverflowException) {
            $name = $this->faker->randomElement($pool).' '.$this->faker->unique()->bothify('##');
        }

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $this->faker->sentence(),
        ];
    }
}
