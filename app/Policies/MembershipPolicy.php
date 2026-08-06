<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\User;

class MembershipPolicy
{
    public function suspend(User $actor, Membership $membership): bool
    {
        return $membership->isActive()
            && $this->canManageStatus($actor, $membership);
    }

    public function reactivate(User $actor, Membership $membership): bool
    {
        return $membership->isSuspended()
            && $this->canManageStatus($actor, $membership);
    }

    public function remove(User $actor, Membership $membership): bool
    {
        return ! $membership->isRemoved()
            && $this->canManage(
                $actor,
                $membership,
                TeamPermission::RemoveMember,
            );
    }

    public function updateRole(User $actor, Membership $membership): bool
    {
        return $membership->role !== TeamRole::Owner
            && $this->canManage(
                $actor,
                $membership,
                TeamPermission::UpdateMember,
            );
    }

    private function canManageStatus(User $actor, Membership $membership): bool
    {
        return $this->canManage(
            $actor,
            $membership,
            TeamPermission::ManageMemberStatus,
        );
    }

    private function canManage(
        User $actor,
        Membership $membership,
        TeamPermission $permission,
    ): bool {
        if ($membership->team->is_personal || $actor->id === $membership->user_id || $membership->isRemoved()) {
            return false;
        }

        $actorRole = $actor->teamRole($membership->team);

        if ($membership->role === TeamRole::Owner && $actorRole !== TeamRole::Owner) {
            return false;
        }

        return $actor->hasTeamPermission(
            $membership->team,
            $permission,
        );
    }
}
