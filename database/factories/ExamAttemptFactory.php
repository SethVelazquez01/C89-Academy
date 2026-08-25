<?php

namespace Database\Factories;

use App\Enums\ExamAttemptStatus;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamAttempt>
 */
class ExamAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'exam_id' => Exam::factory()->published(),
            'user_id' => User::factory(),
            'attempt_number' => 1,
            'status' => ExamAttemptStatus::InProgress,
            'max_points' => 10,
            'earned_points' => null,
            'percentage_score' => null,
            'timed_out' => false,
            'security_event_count' => 0,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'last_activity_at' => now(),
            'submitted_at' => null,
            'graded_at' => null,
        ];
    }
}
