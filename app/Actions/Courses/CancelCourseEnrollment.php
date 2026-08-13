<?php

namespace App\Actions\Courses;

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CancelCourseEnrollment
{
    /**
     * Cancel an active enrollment while preserving its progress and history.
     */
    public function handle(User $administrator, CourseEnrollment $courseEnrollment): CourseEnrollment
    {
        return DB::transaction(function () use ($administrator, $courseEnrollment): CourseEnrollment {
            $teamId = Course::query()
                ->whereKey($courseEnrollment->course_id)
                ->value('team_id');

            abort_if($teamId === null, 404);

            $lockedTeam = Team::query()
                ->whereKey($teamId)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedCourse = Course::query()
                ->where('team_id', $lockedTeam->id)
                ->lockForUpdate()
                ->findOrFail($courseEnrollment->course_id);
            $lockedEnrollment = CourseEnrollment::query()
                ->where('course_id', $lockedCourse->id)
                ->lockForUpdate()
                ->findOrFail($courseEnrollment->id);

            $lockedEnrollment->setRelation('course', $lockedCourse);

            Gate::forUser($administrator)->authorize('cancel', $lockedEnrollment);

            if ($lockedEnrollment->status !== EnrollmentStatus::Active) {
                throw ValidationException::withMessages([
                    'enrollment' => ['Solo se pueden cancelar inscripciones activas.'],
                ]);
            }

            $lockedEnrollment->update([
                'status' => EnrollmentStatus::Cancelled,
                'last_cancelled_by' => $administrator->id,
                'last_cancelled_at' => now(),
            ]);

            return $lockedEnrollment->refresh();
        });
    }
}
