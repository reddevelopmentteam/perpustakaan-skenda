<?php

namespace Database\Factories;

use App\Enums\StudentStatus;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = now();
        $academicYearStart = $now->month >= 7
            ? $now->copy()->month(7)->day(1)->startOfDay()
            : $now->copy()->subYear()->month(7)->day(1)->startOfDay();

        return [
            'user_id' => User::factory(),
            'student_number' => $this->faker->unique()->numerify('252####'),
            'name' => $this->faker->name(),
            'class' => $this->faker->randomElement(['X-1', 'X-2', 'XI-1', 'XI-2', 'XII-1', 'XII-2']),
            'major' => $this->faker->randomElement(['IPA', 'IPS', 'Bahasa', 'TKJ', 'Akuntansi']),
            'phone' => $this->faker->phoneNumber(),
            'email' => $this->faker->unique()->safeEmail(),
            'address' => $this->faker->address(),
            'status' => StudentStatus::ACTIVE,
            'joined_at' => $this->faker->dateTimeBetween($academicYearStart, 'now'),
        ];
    }
}
