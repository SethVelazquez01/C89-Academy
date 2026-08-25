<?php

namespace App\Actions\Exams;

use App\Enums\ExamAttemptStatus;
use App\Enums\ExamQuestionType;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SubmitExamAttempt
{
    public function __construct(public FinalizeExamAttempt $finalizeExamAttempt) {}

    public function handle(User $user, ExamAttempt $attempt, bool $timedOut = false): ExamAttempt
    {
        return DB::transaction(function () use ($user, $attempt, $timedOut): ExamAttempt {
            $lockedAttempt = ExamAttempt::query()
                ->with(['exam', 'questions'])
                ->lockForUpdate()
                ->findOrFail($attempt->id);

            Gate::forUser($user)->authorize('update', $lockedAttempt);

            foreach ($lockedAttempt->questions as $question) {
                if ($question->type->requiresManualReview()) {
                    if (blank($question->answer)) {
                        $question->update([
                            'is_correct' => false,
                            'awarded_points' => 0,
                            'reviewed_at' => now(),
                        ]);
                    }

                    continue;
                }

                $isCorrect = filled($question->answer)
                    && trim((string) $question->answer) === trim((string) $question->correct_answer);

                $question->update([
                    'is_correct' => $isCorrect,
                    'awarded_points' => $isCorrect ? $question->points : 0,
                ]);
            }

            $lockedAttempt->update([
                'submitted_at' => now(),
                'last_activity_at' => now(),
                'timed_out' => $timedOut,
            ]);

            $requiresReview = $lockedAttempt->questions()
                ->where('type', ExamQuestionType::ShortAnswer)
                ->whereNotNull('answer')
                ->whereNull('awarded_points')
                ->exists();

            if ($requiresReview) {
                $lockedAttempt->update([
                    'status' => ExamAttemptStatus::PendingReview,
                    'earned_points' => $lockedAttempt->questions()->sum('awarded_points'),
                    'percentage_score' => null,
                ]);

                return $lockedAttempt->refresh();
            }

            return $this->finalizeExamAttempt->handle($lockedAttempt);
        });
    }
}
