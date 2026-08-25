<?php

namespace Database\Factories;

use App\Enums\ExamQuestionType;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamAttemptQuestion>
 */
class ExamAttemptQuestionFactory extends Factory
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
            'exam_question_id' => null,
            'type' => ExamQuestionType::SingleChoice,
            'prompt' => fake()->sentence().'?',
            'options' => ['Opción correcta', 'Opción incorrecta'],
            'correct_answer' => 'Opción correcta',
            'points' => 1,
            'position' => 1,
            'answer' => null,
            'is_correct' => null,
            'awarded_points' => null,
            'reviewed_by' => null,
            'review_comment' => null,
            'answered_at' => null,
            'reviewed_at' => null,
        ];
    }
}
