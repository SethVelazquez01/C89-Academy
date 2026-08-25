<?php

namespace App\Policies;

use App\Enums\ExamAttemptStatus;
use App\Enums\TeamPermission;
use App\Models\ExamAttempt;
use App\Models\User;

class ExamAttemptPolicy
{
    public function view(User $user, ExamAttempt $examAttempt): bool
    {
        if (! $user->isCurrentTeam($examAttempt->exam->team)
            || ! $user->belongsToTeam($examAttempt->exam->team)) {
            return false;
        }

        return $examAttempt->user_id === $user->id
            || $user->hasTeamPermission($examAttempt->exam->team, TeamPermission::ReviewExam);
    }

    public function update(User $user, ExamAttempt $examAttempt): bool
    {
        return $this->view($user, $examAttempt)
            && $examAttempt->user_id === $user->id
            && $examAttempt->status === ExamAttemptStatus::InProgress;
    }

    public function review(User $user, ExamAttempt $examAttempt): bool
    {
        return $user->isCurrentTeam($examAttempt->exam->team)
            && $user->belongsToTeam($examAttempt->exam->team)
            && $user->hasTeamPermission($examAttempt->exam->team, TeamPermission::ReviewExam);
    }

    public function delete(User $user, ExamAttempt $examAttempt): bool
    {
        return false;
    }

    public function restore(User $user, ExamAttempt $examAttempt): bool
    {
        return false;
    }

    public function forceDelete(User $user, ExamAttempt $examAttempt): bool
    {
        return false;
    }
}
