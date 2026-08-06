<?php

namespace App\Actions\Teams;

use App\Enums\MembershipStatus;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CancelInitialOrganizationAdministratorInvitation
{
    /**
     * Cancel an unaccepted initial administrator invitation.
     */
    public function handle(User $actor, Team $organization, string $invitationCode): void
    {
        DB::transaction(function () use ($actor, $organization, $invitationCode): void {
            $lockedOrganization = Team::query()
                ->lockForUpdate()
                ->findOrFail($organization->id);

            Gate::forUser($actor)->authorize('cancelAdministratorInvitationGlobally', $lockedOrganization);

            $hasAdministrator = $lockedOrganization->memberships()
                ->whereIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
                ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Suspended->value])
                ->exists();

            if ($hasAdministrator) {
                throw ValidationException::withMessages([
                    'administratorInvitation' => [__('Initial administrator invitations can only be managed before the organization has an administrator.')],
                ]);
            }

            $invitation = $lockedOrganization->invitations()
                ->where('code', $invitationCode)
                ->where('role', TeamRole::Admin->value)
                ->whereNull('accepted_at')
                ->lockForUpdate()
                ->firstOrFail();

            $invitation->delete();
        });
    }
}
