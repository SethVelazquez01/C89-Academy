<?php

namespace App\Actions\Teams;

use App\Enums\MembershipStatus;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteOrganization
{
    /**
     * Soft delete an empty real organization as the global platform owner.
     */
    public function handle(User $actor, Team $organization): void
    {
        DB::transaction(function () use ($actor, $organization): void {
            $lockedOrganization = Team::query()
                ->lockForUpdate()
                ->findOrFail($organization->id);

            Gate::forUser($actor)->authorize('deleteGlobally', $lockedOrganization);

            $hasDependencies = $lockedOrganization->memberships()
                ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Suspended->value])
                ->exists()
                || $lockedOrganization->courses()->withTrashed()->exists()
                || $lockedOrganization->invitations()->exists()
                || User::query()->where('current_team_id', $lockedOrganization->id)->exists();

            if ($hasDependencies) {
                throw ValidationException::withMessages([
                    'organization' => [__('This organization cannot be deleted while it has members, courses, or invitations.')],
                ]);
            }

            $lockedOrganization->delete();
        });
    }
}
