<?php

namespace App\Actions\Courses;

use App\Enums\EnrollmentStatus;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class MarkLessonComplete
{
    /**
     * Complete one lesson and finish the enrollment when all content is done.
     */
    public function handle(
        User $user,
        CourseEnrollment $courseEnrollment,
        Lesson $lesson,
    ): LessonProgress {
        Gate::forUser($user)->authorize('complete', [
            LessonProgress::class,
            $courseEnrollment,
            $lesson,
        ]);

        return DB::transaction(function () use ($courseEnrollment, $lesson): LessonProgress {
            $lockedEnrollment = CourseEnrollment::query()
                ->whereKey($courseEnrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lessonProgress = LessonProgress::query()->firstOrNew([
                'course_enrollment_id' => $lockedEnrollment->id,
                'lesson_id' => $lesson->id,
            ]);

            if (! $lessonProgress->exists) {
                $lessonProgress->started_at = Carbon::now();
            }

            if ($lessonProgress->completed_at === null) {
                $lessonProgress->completed_at = Carbon::now();
            }

            $lessonProgress->save();

            $publishedLessons = Lesson::query()
                ->where('is_published', true)
                ->whereHas('courseModule', fn ($query) => $query
                    ->where('course_id', $lockedEnrollment->course_id)
                    ->where('is_published', true));
            $totalLessons = (clone $publishedLessons)->count();
            $completedLessons = $lockedEnrollment
                ->lessonProgress()
                ->whereNotNull('completed_at')
                ->whereIn('lesson_id', (clone $publishedLessons)->select('id'))
                ->count();

            if ($totalLessons > 0 && $completedLessons >= $totalLessons) {
                $lockedEnrollment->update([
                    'status' => EnrollmentStatus::Completed,
                    'completed_at' => $lockedEnrollment->completed_at ?? now(),
                ]);
            }

            return $lessonProgress;
        });
    }
}
