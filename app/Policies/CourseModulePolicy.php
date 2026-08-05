<?php

namespace App\Policies;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\TeamPermission;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\User;

class CourseModulePolicy
{
    /**
     * Determine whether the user can view modules in the current organization.
     */
    public function viewAny(User $user): bool
    {
        return $user->currentTeam !== null
            && $user->belongsToTeam($user->currentTeam);
    }

    /**
     * Administrators can preview all modules; collaborators need an enrollment.
     */
    public function view(User $user, CourseModule $courseModule): bool
    {
        $course = $courseModule->course;

        if (! $this->isCurrentTeamCourse($user, $course)) {
            return false;
        }

        if ($user->hasTeamPermission($course->team, TeamPermission::UpdateCourse)) {
            return true;
        }

        return $course->status === CourseStatus::Published
            && $courseModule->is_published
            && $this->hasAvailableEnrollment($user, $course);
    }

    /**
     * Determine whether the user can add a module to the course.
     */
    public function create(User $user, Course $course): bool
    {
        return $this->canManage($user, $course);
    }

    /**
     * Determine whether the user can update the module.
     */
    public function update(User $user, CourseModule $courseModule): bool
    {
        return $this->canManage($user, $courseModule->course);
    }

    /**
     * Determine whether the user can delete the module.
     */
    public function delete(User $user, CourseModule $courseModule): bool
    {
        return $this->canManage($user, $courseModule->course);
    }

    /**
     * Determine whether the user can restore the module.
     */
    public function restore(User $user, CourseModule $courseModule): bool
    {
        return $this->canManage($user, $courseModule->course);
    }

    /**
     * Permanently deleting modules is intentionally disabled.
     */
    public function forceDelete(User $user, CourseModule $courseModule): bool
    {
        return false;
    }

    private function canManage(User $user, Course $course): bool
    {
        return $this->isCurrentTeamCourse($user, $course)
            && $user->hasTeamPermission($course->team, TeamPermission::UpdateCourse);
    }

    private function isCurrentTeamCourse(User $user, Course $course): bool
    {
        return $user->currentTeam !== null
            && $user->isCurrentTeam($course->team)
            && $user->belongsToTeam($course->team);
    }

    private function hasAvailableEnrollment(User $user, Course $course): bool
    {
        return $user->courseEnrollments()
            ->where('course_id', $course->id)
            ->whereIn('status', [
                EnrollmentStatus::Active->value,
                EnrollmentStatus::Completed->value,
            ])
            ->exists();
    }
}
