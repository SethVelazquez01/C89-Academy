<?php

namespace App\Actions\Courses;

use App\Enums\EnrollmentStatus;
use App\Enums\MembershipStatus;
use App\Enums\TeamRole;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AssignCourseToCollaborator
{
    /**
     * Assign a published course without discarding prior progress or history.
     */
    public function handle(
        User $administrator,
        User $collaborator,
        Course $course,
        bool $isRequired,
        ?CarbonInterface $dueAt = null,
    ): CourseEnrollment {
        if ($dueAt?->isBefore(today())) {
            throw ValidationException::withMessages([
                'dueDate' => ['La fecha límite no puede estar en el pasado.'],
            ]);
        }

        return DB::transaction(function () use (
            $administrator,
            $collaborator,
            $course,
            $isRequired,
            $dueAt,
        ): CourseEnrollment {
            $lockedTeam = Team::query()->lockForUpdate()->findOrFail($course->team_id);
            $lockedCourse = Course::query()
                ->where('team_id', $lockedTeam->id)
                ->lockForUpdate()
                ->findOrFail($course->id);
            $membership = Membership::query()
                ->where('team_id', $lockedTeam->id)
                ->where('user_id', $collaborator->id)
                ->lockForUpdate()
                ->first();

            if ($membership === null
                || $membership->role !== TeamRole::Member
                || $membership->status !== MembershipStatus::Active) {
                throw ValidationException::withMessages([
                    'collaboratorId' => ['Selecciona un colaborador activo de esta organización.'],
                ]);
            }

            Gate::forUser($administrator)->authorize(
                'assign',
                [CourseEnrollment::class, $lockedCourse, $collaborator],
            );

            $enrollment = CourseEnrollment::query()
                ->where('course_id', $lockedCourse->id)
                ->where('user_id', $collaborator->id)
                ->lockForUpdate()
                ->first();
            $assignment = [
                'assigned_by' => $administrator->id,
                'is_required' => $isRequired,
                'due_at' => $dueAt,
            ];

            if ($enrollment === null) {
                return CourseEnrollment::query()->create([
                    'course_id' => $lockedCourse->id,
                    'user_id' => $collaborator->id,
                    ...$assignment,
                    'status' => EnrollmentStatus::Active,
                    'enrolled_at' => now(),
                    'completed_at' => null,
                ]);
            }

            if ($enrollment->status === EnrollmentStatus::Cancelled) {
                $assignment['status'] = EnrollmentStatus::Active;
                $assignment['enrolled_at'] = now();
                $assignment['completed_at'] = null;
            }

            $enrollment->update($assignment);

            return $enrollment->refresh();
        });
    }
}
