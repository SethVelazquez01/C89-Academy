<?php

namespace App\Policies;

use App\Enums\CourseStatus;
use App\Enums\TeamPermission;
use App\Models\Course;
use App\Models\Team;
use App\Models\User;

class CoursePolicy
{
    /**
     * Determine whether the user can view the course catalog.
     */
    public function viewAny(User $user): bool
    {
        $team = $user->currentTeam;

        return $team !== null && $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can view the course.
     */
    public function view(User $user, Course $course): bool
    {
        if (! $this->isCurrentTeamCourse($user, $course)) {
            return false;
        }

        return $course->status === CourseStatus::Published
            || $user->hasTeamPermission($course->team, TeamPermission::UpdateCourse);
    }

    /**
     * Determine whether the user can create courses.
     */
    public function create(User $user): bool
    {
        $team = $user->currentTeam;

        return $team !== null
            && $user->hasTeamPermission($team, TeamPermission::CreateCourse);
    }

    /**
     * Determine whether the user can update the course.
     */
    public function update(User $user, Course $course): bool
    {
        return $this->hasCoursePermission($user, $course, TeamPermission::UpdateCourse);
    }

    /**
     * Determine whether the user can publish or unpublish the course.
     */
    public function publish(User $user, Course $course): bool
    {
        return $this->hasCoursePermission($user, $course, TeamPermission::PublishCourse);
    }

    /**
     * Determine whether the user can delete the course.
     */
    public function delete(User $user, Course $course): bool
    {
        return $this->hasCoursePermission($user, $course, TeamPermission::DeleteCourse);
    }

    /**
     * Determine whether the user can restore the course.
     */
    public function restore(User $user, Course $course): bool
    {
        return $this->hasCoursePermission($user, $course, TeamPermission::DeleteCourse);
    }

    /**
     * Permanently deleting courses is intentionally disabled.
     */
    public function forceDelete(User $user, Course $course): bool
    {
        return false;
    }

    /**
     * Determine whether the user has a permission for a course in the current organization.
     */
    private function hasCoursePermission(User $user, Course $course, TeamPermission $permission): bool
    {
        return $this->isCurrentTeamCourse($user, $course)
            && $user->hasTeamPermission($course->team, $permission);
    }

    /**
     * Determine whether the course belongs to the currently selected organization.
     */
    private function isCurrentTeamCourse(User $user, Course $course): bool
    {
        return $user->currentTeam instanceof Team
            && $user->isCurrentTeam($course->team)
            && $user->belongsToTeam($course->team);
    }
}
