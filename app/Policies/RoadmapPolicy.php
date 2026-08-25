<?php

namespace App\Policies;

use App\Enums\RoadmapAssignmentStatus;
use App\Enums\RoadmapStatus;
use App\Enums\TeamPermission;
use App\Models\Roadmap;
use App\Models\Team;
use App\Models\User;

class RoadmapPolicy
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
    public function view(User $user, Roadmap $roadmap): bool
    {
        if (! $this->isCurrentTeamRoadmap($user, $roadmap)) {
            return false;
        }

        if ($user->hasTeamPermission($roadmap->team, TeamPermission::UpdateRoadmap)) {
            return true;
        }

        return $roadmap->status === RoadmapStatus::Published
            && $roadmap->assignments()
                ->where('user_id', $user->id)
                ->where('status', RoadmapAssignmentStatus::Active)
                ->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        $team = $user->currentTeam;

        return $team !== null && $user->hasTeamPermission($team, TeamPermission::CreateRoadmap);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Roadmap $roadmap): bool
    {
        return $this->hasPermission($user, $roadmap, TeamPermission::UpdateRoadmap);
    }

    public function publish(User $user, Roadmap $roadmap): bool
    {
        return $this->hasPermission($user, $roadmap, TeamPermission::PublishRoadmap);
    }

    public function assign(User $user, Roadmap $roadmap): bool
    {
        return $this->hasPermission($user, $roadmap, TeamPermission::AssignRoadmap);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Roadmap $roadmap): bool
    {
        return $this->hasPermission($user, $roadmap, TeamPermission::DeleteRoadmap);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Roadmap $roadmap): bool
    {
        return $this->hasPermission($user, $roadmap, TeamPermission::DeleteRoadmap);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Roadmap $roadmap): bool
    {
        return false;
    }

    private function hasPermission(User $user, Roadmap $roadmap, TeamPermission $permission): bool
    {
        return $this->isCurrentTeamRoadmap($user, $roadmap)
            && $user->hasTeamPermission($roadmap->team, $permission);
    }

    private function isCurrentTeamRoadmap(User $user, Roadmap $roadmap): bool
    {
        return $user->currentTeam instanceof Team
            && $user->isCurrentTeam($roadmap->team)
            && $user->belongsToTeam($roadmap->team);
    }
}
