<?php

namespace Database\Factories;

use App\Enums\ExamSecurityLevel;
use App\Enums\ExamStatus;
use App\Models\Exam;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exam>
 */
class ExamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'created_by' => null,
            'title' => fake()->sentence(4),
            'summary' => fake()->sentence(12),
            'instructions' => fake()->paragraph(),
            'status' => ExamStatus::Draft,
            'security_level' => ExamSecurityLevel::Controlled,
            'time_limit_minutes' => 30,
            'passing_score' => 80,
            'max_attempts' => 1,
            'randomize_questions' => true,
            'randomize_options' => true,
            'allow_backtracking' => false,
            'fullscreen_required' => true,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ExamStatus::Published,
            'published_at' => now(),
        ]);
    }
}
