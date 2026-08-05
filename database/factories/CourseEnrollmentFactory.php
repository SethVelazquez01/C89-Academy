<?php

namespace Database\Factories;

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseEnrollment>
 */
class CourseEnrollmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory()->published(),
            'user_id' => User::factory(),
            'assigned_by' => null,
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
            'completed_at' => null,
        ];
    }

    /**
     * Indicate that the enrollment is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EnrollmentStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the enrollment is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EnrollmentStatus::Cancelled,
            'completed_at' => null,
        ]);
    }
}
