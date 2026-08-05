<?php

namespace Database\Factories;

use App\Enums\LessonType;
use App\Models\CourseModule;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_module_id' => CourseModule::factory(),
            'title' => fake()->sentence(5),
            'type' => LessonType::Text,
            'content' => fake()->paragraphs(3, true),
            'resource_disk' => null,
            'resource_path' => null,
            'resource_name' => null,
            'resource_mime' => null,
            'resource_size' => null,
            'external_url' => null,
            'estimated_duration_minutes' => fake()->numberBetween(5, 60),
            'position' => fake()->numberBetween(1, 10),
            'is_published' => false,
        ];
    }

    /**
     * Indicate that the lesson is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => true,
        ]);
    }

    /**
     * Indicate that the lesson contains an external video.
     */
    public function video(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LessonType::Video,
            'content' => null,
            'external_url' => 'https://example.com/training-video',
        ]);
    }

    /**
     * Indicate that the lesson contains a private PDF document.
     */
    public function document(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LessonType::Document,
            'content' => null,
            'resource_disk' => 'local',
            'resource_path' => 'course-resources/example.pdf',
            'resource_name' => 'example.pdf',
            'resource_mime' => 'application/pdf',
            'resource_size' => 1024,
            'external_url' => null,
        ]);
    }
}
