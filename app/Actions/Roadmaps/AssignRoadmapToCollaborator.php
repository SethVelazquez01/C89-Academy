<?php

namespace App\Actions\Roadmaps;

use App\Actions\Courses\AssignCourseToCollaborator;
use App\Enums\EnrollmentStatus;
use App\Enums\MembershipStatus;
use App\Enums\RoadmapAssignmentStatus;
use App\Enums\RoadmapStatus;
use App\Enums\TeamRole;
use App\Models\CourseEnrollment;
use App\Models\Membership;
use App\Models\Roadmap;
use App\Models\RoadmapAssignment;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AssignRoadmapToCollaborator
{
    public function __construct(public AssignCourseToCollaborator $assignCourse) {}

    public function handle(
        User $administrator,
        User $collaborator,
        Roadmap $roadmap,
        ?CarbonInterface $dueAt = null,
    ): RoadmapAssignment {
        if ($dueAt?->isBefore(today())) {
            throw ValidationException::withMessages(['dueDate' => ['La fecha límite no puede estar en el pasado.']]);
        }

        return DB::transaction(function () use ($administrator, $collaborator, $roadmap, $dueAt): RoadmapAssignment {
            $team = Team::query()->lockForUpdate()->findOrFail($roadmap->team_id);
            $lockedRoadmap = Roadmap::query()
                ->where('team_id', $team->id)
                ->lockForUpdate()
                ->findOrFail($roadmap->id);
            $membership = Membership::query()
                ->where('team_id', $team->id)
                ->where('user_id', $collaborator->id)
                ->lockForUpdate()
                ->first();

            if ($membership === null
                || $membership->role !== TeamRole::Member
                || $membership->status !== MembershipStatus::Active) {
                throw ValidationException::withMessages(['collaboratorId' => ['Selecciona un colaborador activo de esta organización.']]);
            }

            Gate::forUser($administrator)->authorize('assign', $lockedRoadmap);

            if ($lockedRoadmap->status !== RoadmapStatus::Published) {
                throw ValidationException::withMessages(['roadmap' => ['Publica el roadmap antes de asignarlo.']]);
            }

            $assignment = RoadmapAssignment::query()
                ->where('roadmap_id', $lockedRoadmap->id)
                ->where('user_id', $collaborator->id)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'assigned_by' => $administrator->id,
                'cancelled_by' => null,
                'status' => RoadmapAssignmentStatus::Active,
                'assigned_at' => now(),
                'due_at' => $dueAt,
                'cancelled_at' => null,
            ];

            if ($assignment === null) {
                $assignment = RoadmapAssignment::query()->create([
                    'roadmap_id' => $lockedRoadmap->id,
                    'user_id' => $collaborator->id,
                    ...$attributes,
                ]);
            } else {
                $assignment->update($attributes);
            }

            foreach ($lockedRoadmap->courses()->get() as $course) {
                $existingEnrollment = CourseEnrollment::query()
                    ->where('course_id', $course->id)
                    ->where('user_id', $collaborator->id)
                    ->first();
                $shouldRecordSource = $existingEnrollment === null
                    || $existingEnrollment->status === EnrollmentStatus::Cancelled;

                $enrollment = $this->assignCourse->handle(
                    $administrator,
                    $collaborator,
                    $course,
                    true,
                    $dueAt,
                );

                if ($shouldRecordSource) {
                    $enrollment->update(['roadmap_assignment_id' => $assignment->id]);
                }
            }

            return $assignment->refresh();
        });
    }
}
