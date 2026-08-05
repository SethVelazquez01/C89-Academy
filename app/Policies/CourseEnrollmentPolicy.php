<?php

namespace App\Policies;

use App\Enums\CourseStatus;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;

class CourseEnrollmentPolicy
{
    /**
     * Determine whether the user can view enrollments in the current organization.
     */
    public function viewAny(User $user): bool
    {
        return $user->currentTeam !== null
            && $user->belongsToTeam($user->currentTeam);
    }

    /**
     * Determine whether the user can view the enrollment.
     */
    public function view(User $user, CourseEnrollment $courseEnrollment): bool
    {
        if (! $user->isCurrentTeam($courseEnrollment->course->team)) {
            return false;
        }

        return $courseEnrollment->user_id === $user->id
            || $user->hasTeamPermission($courseEnrollment->course->team, TeamPermission::UpdateCourse);
    }

    /**
     * Only collaborators can enroll themselves in published courses.
     */
    public function create(User $user, Course $course): bool
    {
        return $course->status === CourseStatus::Published
            && $user->isCurrentTeam($course->team)
            && $user->belongsToTeam($course->team)
            && $user->teamRole($course->team) === TeamRole::Member;
    }

    /**
     * Determine whether the user can update the enrollment.
     */
    public function update(User $user, CourseEnrollment $courseEnrollment): bool
    {
        return $this->view($user, $courseEnrollment);
    }

    /**
     * Enrollment history cannot be deleted.
     */
    public function delete(User $user, CourseEnrollment $courseEnrollment): bool
    {
        return false;
    }

    public function restore(User $user, CourseEnrollment $courseEnrollment): bool
    {
        return false;
    }

    public function forceDelete(User $user, CourseEnrollment $courseEnrollment): bool
    {
        return false;
    }
}
