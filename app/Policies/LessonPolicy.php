<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\User;

class LessonPolicy
{
    /**
     * Determine whether the user can view lessons in the current organization.
     */
    public function viewAny(User $user): bool
    {
        return $user->currentTeam !== null
            && $user->belongsToTeam($user->currentTeam);
    }

    /**
     * Determine whether the user can view the lesson.
     */
    public function view(User $user, Lesson $lesson): bool
    {
        $courseModule = $lesson->courseModule;
        $course = $courseModule->course;

        if ($this->canManage($user, $course)) {
            return true;
        }

        return $lesson->is_published
            && (new CourseModulePolicy)->view($user, $courseModule);
    }

    /**
     * Determine whether the user can add a lesson to the module.
     */
    public function create(User $user, CourseModule $courseModule): bool
    {
        return $this->canManage($user, $courseModule->course);
    }

    /**
     * Determine whether the user can update the lesson.
     */
    public function update(User $user, Lesson $lesson): bool
    {
        return $this->canManage($user, $lesson->courseModule->course);
    }

    /**
     * Determine whether the user can delete the lesson.
     */
    public function delete(User $user, Lesson $lesson): bool
    {
        return $this->canManage($user, $lesson->courseModule->course);
    }

    /**
     * Determine whether the user can restore the lesson.
     */
    public function restore(User $user, Lesson $lesson): bool
    {
        return $this->canManage($user, $lesson->courseModule->course);
    }

    /**
     * Permanently deleting lessons is intentionally disabled.
     */
    public function forceDelete(User $user, Lesson $lesson): bool
    {
        return false;
    }

    private function canManage(User $user, Course $course): bool
    {
        return $user->currentTeam !== null
            && $user->isCurrentTeam($course->team)
            && $user->belongsToTeam($course->team)
            && $user->hasTeamPermission($course->team, TeamPermission::UpdateCourse);
    }
}
