<?php

namespace App\Actions\Exams;

use App\Enums\ExamAttemptStatus;
use App\Enums\ExamQuestionType;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReviewExamAnswer
{
    public function __construct(public FinalizeExamAttempt $finalizeExamAttempt) {}

    public function handle(
        User $reviewer,
        ExamAttemptQuestion $question,
        int $awardedPoints,
        ?string $comment = null,
    ): ExamAttempt {
        return DB::transaction(function () use ($reviewer, $question, $awardedPoints, $comment): ExamAttempt {
            $lockedQuestion = ExamAttemptQuestion::query()
                ->with('attempt.exam')
                ->lockForUpdate()
                ->findOrFail($question->id);
            $attempt = $lockedQuestion->attempt;

            Gate::forUser($reviewer)->authorize('review', $attempt);

            if ($attempt->status !== ExamAttemptStatus::PendingReview
                || $lockedQuestion->type !== ExamQuestionType::ShortAnswer) {
                throw ValidationException::withMessages([
                    'review' => ['Esta respuesta no está pendiente de revisión.'],
                ]);
            }

            if ($awardedPoints < 0 || $awardedPoints > $lockedQuestion->points) {
                throw ValidationException::withMessages([
                    'awardedPoints' => ["Asigna entre 0 y {$lockedQuestion->points} puntos."],
                ]);
            }

            $lockedQuestion->update([
                'awarded_points' => $awardedPoints,
                'is_correct' => $awardedPoints === $lockedQuestion->points,
                'reviewed_by' => $reviewer->id,
                'review_comment' => filled($comment) ? trim($comment) : null,
                'reviewed_at' => now(),
            ]);

            $hasPendingAnswers = $attempt->questions()
                ->where('type', ExamQuestionType::ShortAnswer)
                ->whereNotNull('answer')
                ->whereNull('awarded_points')
                ->exists();

            if ($hasPendingAnswers) {
                $attempt->update(['earned_points' => $attempt->questions()->sum('awarded_points')]);

                return $attempt->refresh();
            }

            return $this->finalizeExamAttempt->handle($attempt);
        });
    }
}
