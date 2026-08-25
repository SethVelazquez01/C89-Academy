<?php

namespace Database\Factories;

use App\Enums\ExamQuestionType;
use App\Models\Exam;
use App\Models\ExamQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamQuestion>
 */
class ExamQuestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),
            'type' => ExamQuestionType::SingleChoice,
            'prompt' => fake()->sentence().'?',
            'options' => ['Opción A', 'Opción B', 'Opción C'],
            'correct_answer' => 'Opción A',
            'explanation' => fake()->sentence(),
            'points' => 1,
            'position' => 1,
        ];
    }
}
