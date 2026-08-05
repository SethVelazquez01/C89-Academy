<?php

namespace Database\Factories;

use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LessonProgress>
 */
class LessonProgressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_enrollment_id' => CourseEnrollment::factory(),
            'lesson_id' => Lesson::factory()->published(),
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    /**
     * Indicate that the lesson has been completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed_at' => now(),
        ]);
    }
}
