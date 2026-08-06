<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Determine whether the user can view their organization list.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the organization.
     */
    public function view(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Only the global platform owner can create real organizations.
     */
    public function create(User $user): bool
    {
        return $user->isPlatformOwner();
    }

    /**
     * Preserve one personal team per identity for framework compatibility.
     */
    public function createPersonal(User $user): bool
    {
        return $user->personalTeam() === null;
    }

    /**
     * Determine whether the user can update the organization.
     */
    public function update(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::UpdateTeam);
    }

    /**
     * Allow only the global platform owner to update a real organization globally.
     */
    public function updateGlobally(User $user, Team $team): bool
    {
        return $user->isPlatformOwner() && ! $team->is_personal;
    }

    /**
     * Determine whether the user can leave the organization.
     */
    public function leave(User $user, Team $team): bool
    {
        return ! $team->is_personal
            && $user->belongsToTeam($team)
            && ! $user->ownsTeam($team);
    }

    /**
     * Determine whether the user can add a member to the organization.
     */
    public function addMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::AddMember);
    }

    /**
     * Determine whether the user can update an organization member.
     */
    public function updateMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::UpdateMember);
    }

    /**
     * Determine whether the user can remove an organization member.
     */
    public function removeMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::RemoveMember);
    }

    /**
     * Determine whether the user can invite organization members.
     */
    public function inviteMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::CreateInvitation);
    }

    /**
     * Determine whether the user can cancel invitations.
     */
    public function cancelInvitation(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::CancelInvitation);
    }

    /**
     * Determine whether the user can delete the organization.
     */
    public function delete(User $user, Team $team): bool
    {
        return ! $team->is_personal
            && $user->hasTeamPermission($team, TeamPermission::DeleteTeam);
    }

    /**
     * Allow only the global platform owner to delete a real organization globally.
     */
    public function deleteGlobally(User $user, Team $team): bool
    {
        return $user->isPlatformOwner() && ! $team->is_personal;
    }

    /**
     * Allow only the global platform owner to assign the first organization administrator.
     */
    public function assignAdministratorGlobally(User $user, Team $team): bool
    {
        return $user->isPlatformOwner() && ! $team->is_personal;
    }

    /**
     * Allow only the global platform owner to cancel the initial admin invitation.
     */
    public function cancelAdministratorInvitationGlobally(User $user, Team $team): bool
    {
        return $user->isPlatformOwner() && ! $team->is_personal;
    }
}
