<?php

namespace App\Actions\Roadmaps;

use App\Actions\Courses\CancelCourseEnrollment;
use App\Enums\EnrollmentStatus;
use App\Enums\RoadmapAssignmentStatus;
use App\Models\RoadmapAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CancelRoadmapAssignment
{
    public function __construct(public CancelCourseEnrollment $cancelCourseEnrollment) {}

    public function handle(User $administrator, RoadmapAssignment $assignment): RoadmapAssignment
    {
        return DB::transaction(function () use ($administrator, $assignment): RoadmapAssignment {
            $lockedAssignment = RoadmapAssignment::query()
                ->with('roadmap.items')
                ->lockForUpdate()
                ->findOrFail($assignment->id);

            Gate::forUser($administrator)->authorize('assign', $lockedAssignment->roadmap);

            if ($lockedAssignment->status !== RoadmapAssignmentStatus::Active) {
                throw ValidationException::withMessages(['assignment' => ['La asignación ya está cancelada.']]);
            }

            $lockedAssignment->update([
                'status' => RoadmapAssignmentStatus::Cancelled,
                'cancelled_by' => $administrator->id,
                'cancelled_at' => now(),
            ]);

            foreach ($lockedAssignment->roadmap->items->whereNotNull('course_id') as $item) {
                $enrollment = $lockedAssignment->user
                    ->courseEnrollments()
                    ->where('course_id', $item->course_id)
                    ->where('roadmap_assignment_id', $lockedAssignment->id)
                    ->first();

                if ($enrollment === null) {
                    continue;
                }

                $replacement = RoadmapAssignment::query()
                    ->where('user_id', $lockedAssignment->user_id)
                    ->where('status', RoadmapAssignmentStatus::Active)
                    ->whereKeyNot($lockedAssignment->id)
                    ->whereHas('roadmap.items', fn ($query) => $query->where('course_id', $item->course_id))
                    ->first();

                if ($replacement !== null) {
                    $enrollment->update(['roadmap_assignment_id' => $replacement->id]);
                } elseif ($enrollment->status === EnrollmentStatus::Active) {
                    $this->cancelCourseEnrollment->handle($administrator, $enrollment);
                }
            }

            return $lockedAssignment->refresh();
        });
    }
}
