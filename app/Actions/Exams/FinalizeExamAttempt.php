<?php

namespace App\Actions\Exams;

use App\Enums\ExamAttemptStatus;
use App\Models\ExamAttempt;

class FinalizeExamAttempt
{
    public function handle(ExamAttempt $attempt): ExamAttempt
    {
        $earnedPoints = (int) $attempt->questions()->sum('awarded_points');
        $percentage = $attempt->max_points > 0
            ? round(($earnedPoints / $attempt->max_points) * 100, 2)
            : 0;

        $attempt->update([
            'status' => $percentage >= $attempt->exam->passing_score
                ? ExamAttemptStatus::Passed
                : ExamAttemptStatus::Failed,
            'earned_points' => $earnedPoints,
            'percentage_score' => $percentage,
            'graded_at' => now(),
        ]);

        return $attempt->refresh();
    }
}
