<?php

namespace App\Policies;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;

class LessonProgressPolicy
{
    /**
     * Determine whether the user can view progress in the current organization.
     */
    public function viewAny(User $user): bool
    {
        return $user->currentTeam !== null
            && $user->belongsToTeam($user->currentTeam);
    }

    /**
     * Users can view their own progress; course managers can review it.
     */
    public function view(User $user, LessonProgress $lessonProgress): bool
    {
        $enrollment = $lessonProgress->courseEnrollment;
        $course = $enrollment->course;

        if (! $user->isCurrentTeam($course->team)) {
            return false;
        }

        return $enrollment->user_id === $user->id
            || $user->hasTeamPermission($course->team, TeamPermission::UpdateCourse);
    }

    /**
     * Determine whether progress can be created for this lesson.
     */
    public function create(
        User $user,
        CourseEnrollment $courseEnrollment,
        Lesson $lesson,
    ): bool {
        return $this->canTrack($user, $courseEnrollment, $lesson);
    }

    /**
     * Determine whether the lesson can be marked as completed.
     */
    public function complete(
        User $user,
        CourseEnrollment $courseEnrollment,
        Lesson $lesson,
    ): bool {
        return $this->canTrack($user, $courseEnrollment, $lesson);
    }

    /**
     * Determine whether the user can update the progress record.
     */
    public function update(User $user, LessonProgress $lessonProgress): bool
    {
        return $lessonProgress->courseEnrollment->user_id === $user->id
            && $this->view($user, $lessonProgress);
    }

    /**
     * Progress history cannot be deleted.
     */
    public function delete(User $user, LessonProgress $lessonProgress): bool
    {
        return false;
    }

    public function restore(User $user, LessonProgress $lessonProgress): bool
    {
        return false;
    }

    public function forceDelete(User $user, LessonProgress $lessonProgress): bool
    {
        return false;
    }

    private function canTrack(
        User $user,
        CourseEnrollment $courseEnrollment,
        Lesson $lesson,
    ): bool {
        $course = $courseEnrollment->course;
        $courseModule = $lesson->courseModule;

        return $courseEnrollment->user_id === $user->id
            && in_array($courseEnrollment->status, [
                EnrollmentStatus::Active,
                EnrollmentStatus::Completed,
            ], true)
            && $course->status === CourseStatus::Published
            && $user->isCurrentTeam($course->team)
            && $user->belongsToTeam($course->team)
            && $user->teamRole($course->team) === TeamRole::Member
            && $courseModule->course_id === $course->id
            && $courseModule->is_published
            && $lesson->is_published;
    }
}
