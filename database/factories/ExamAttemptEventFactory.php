<?php

namespace Database\Factories;

use App\Enums\ExamAttemptEventType;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamAttemptEvent>
 */
class ExamAttemptEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_attempt_id' => ExamAttempt::factory(),
            'type' => ExamAttemptEventType::TabHidden,
            'metadata' => null,
            'occurred_at' => now(),
        ];
    }
}
