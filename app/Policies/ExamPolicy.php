<?php

namespace App\Policies;

use App\Enums\CourseAccessMode;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamStatus;
use App\Enums\RoadmapAssignmentStatus;
use App\Enums\RoadmapStatus;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Exam;
use App\Models\RoadmapAssignment;
use App\Models\Team;
use App\Models\User;

class ExamPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        $team = $user->currentTeam;

        return $team !== null && $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Exam $exam): bool
    {
        if (! $this->isCurrentTeamExam($user, $exam)) {
            return false;
        }

        return $exam->status === ExamStatus::Published
            || $user->hasTeamPermission($exam->team, TeamPermission::UpdateExam);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        $team = $user->currentTeam;

        return $team !== null
            && $user->hasTeamPermission($team, TeamPermission::CreateExam);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Exam $exam): bool
    {
        return $this->hasExamPermission($user, $exam, TeamPermission::UpdateExam);
    }

    public function publish(User $user, Exam $exam): bool
    {
        return $this->hasExamPermission($user, $exam, TeamPermission::PublishExam);
    }

    public function take(User $user, Exam $exam): bool
    {
        if (! $this->isCurrentTeamExam($user, $exam)
            || $exam->status !== ExamStatus::Published
            || $user->teamRole($exam->team) !== TeamRole::Member) {
            return false;
        }

        $availableThroughCourse = $exam->courses()
            ->where('access_mode', '!=', CourseAccessMode::Blocked)
            ->whereHas('enrollments', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('status', EnrollmentStatus::Completed))
            ->exists();

        if ($availableThroughCourse) {
            return true;
        }

        return RoadmapAssignment::query()
            ->where('user_id', $user->id)
            ->where('status', RoadmapAssignmentStatus::Active)
            ->whereHas('roadmap', fn ($query) => $query
                ->where('status', RoadmapStatus::Published)
                ->whereHas('items', fn ($itemQuery) => $itemQuery->where('exam_id', $exam->id)))
            ->exists();
    }

    public function review(User $user, Exam $exam): bool
    {
        return $this->hasExamPermission($user, $exam, TeamPermission::ReviewExam);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Exam $exam): bool
    {
        return $this->hasExamPermission($user, $exam, TeamPermission::DeleteExam);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Exam $exam): bool
    {
        return $this->hasExamPermission($user, $exam, TeamPermission::DeleteExam);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Exam $exam): bool
    {
        return false;
    }

    private function hasExamPermission(User $user, Exam $exam, TeamPermission $permission): bool
    {
        return $this->isCurrentTeamExam($user, $exam)
            && $user->hasTeamPermission($exam->team, $permission);
    }

    private function isCurrentTeamExam(User $user, Exam $exam): bool
    {
        return $user->currentTeam instanceof Team
            && $user->isCurrentTeam($exam->team)
            && $user->belongsToTeam($exam->team);
    }
}
