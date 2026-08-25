<?php

namespace App\Actions\Exams;

use App\Enums\ExamAttemptStatus;
use App\Enums\ExamQuestionType;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StartExamAttempt
{
    public function __construct(public SubmitExamAttempt $submitExamAttempt) {}

    public function handle(User $user, Exam $exam): ExamAttempt
    {
        Gate::forUser($user)->authorize('take', $exam);

        return DB::transaction(function () use ($user, $exam): ExamAttempt {
            $lockedExam = Exam::query()
                ->where('team_id', $user->current_team_id)
                ->with('questions')
                ->lockForUpdate()
                ->findOrFail($exam->id);

            $activeAttempt = ExamAttempt::query()
                ->where('exam_id', $lockedExam->id)
                ->where('user_id', $user->id)
                ->where('status', ExamAttemptStatus::InProgress)
                ->lockForUpdate()
                ->latest('attempt_number')
                ->first();

            if ($activeAttempt !== null && $activeAttempt->expires_at->isFuture()) {
                return $activeAttempt;
            }

            if ($activeAttempt !== null) {
                $this->submitExamAttempt->handle($user, $activeAttempt, timedOut: true);
            }

            $attemptNumber = ExamAttempt::query()
                ->where('exam_id', $lockedExam->id)
                ->where('user_id', $user->id)
                ->count() + 1;

            if ($attemptNumber > $lockedExam->max_attempts) {
                throw ValidationException::withMessages([
                    'attempt' => ['Ya utilizaste todos los intentos permitidos para este examen.'],
                ]);
            }

            if ($lockedExam->questions->isEmpty()) {
                throw ValidationException::withMessages([
                    'attempt' => ['Este examen todavía no tiene preguntas.'],
                ]);
            }

            $questions = $lockedExam->randomize_questions
                ? $lockedExam->questions->shuffle()->values()
                : $lockedExam->questions->values();

            $attempt = ExamAttempt::query()->create([
                'uuid' => (string) Str::uuid(),
                'exam_id' => $lockedExam->id,
                'user_id' => $user->id,
                'attempt_number' => $attemptNumber,
                'status' => ExamAttemptStatus::InProgress,
                'max_points' => $questions->sum('points'),
                'started_at' => now(),
                'expires_at' => now()->addMinutes($lockedExam->time_limit_minutes),
                'last_activity_at' => now(),
            ]);

            $questions->each(function (ExamQuestion $question, int $index) use ($attempt, $lockedExam): void {
                $options = $question->options;

                if ($lockedExam->randomize_options
                    && $question->type === ExamQuestionType::SingleChoice
                    && $options !== null) {
                    $options = collect($options)->shuffle()->values()->all();
                }

                $attempt->questions()->create([
                    'exam_question_id' => $question->id,
                    'type' => $question->type,
                    'prompt' => $question->prompt,
                    'options' => $options,
                    'correct_answer' => $question->correct_answer,
                    'points' => $question->points,
                    'position' => $index + 1,
                ]);
            });

            return $attempt->refresh();
        });
    }
}
