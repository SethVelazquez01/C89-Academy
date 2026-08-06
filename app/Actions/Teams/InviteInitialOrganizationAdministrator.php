<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteInitialOrganizationAdministrator
{
    /**
     * Invite the first administrator for a real organization.
     */
    public function handle(User $actor, Team $organization, string $email): TeamInvitation
    {
        $normalizedEmail = Str::lower(trim($email));

        return DB::transaction(function () use ($actor, $organization, $normalizedEmail): TeamInvitation {
            $lockedOrganization = Team::query()
                ->lockForUpdate()
                ->findOrFail($organization->id);

            Gate::forUser($actor)->authorize('assignAdministratorGlobally', $lockedOrganization);

            $hasAdministrator = $lockedOrganization->memberships()
                ->whereIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
                ->exists();

            $hasPendingAdministratorInvitation = $lockedOrganization->invitations()
                ->where('role', TeamRole::Admin->value)
                ->whereNull('accepted_at')
                ->where(fn ($query) => $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now()))
                ->exists();

            if ($hasAdministrator || $hasPendingAdministratorInvitation) {
                throw ValidationException::withMessages([
                    'administratorEmail' => [__('This organization already has an administrator or a pending administrator invitation.')],
                ]);
            }

            $isMember = $lockedOrganization->members()
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                ->exists();

            $hasPendingInvitation = $lockedOrganization->invitations()
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                ->whereNull('accepted_at')
                ->where(fn ($query) => $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now()))
                ->exists();

            if ($isMember || $hasPendingInvitation) {
                throw ValidationException::withMessages([
                    'administratorEmail' => [__('This email is already a member or has a pending invitation for the organization.')],
                ]);
            }

            return $lockedOrganization->invitations()->create([
                'email' => $normalizedEmail,
                'role' => TeamRole::Admin,
                'invited_by' => $actor->id,
                'expires_at' => now()->addDays(3),
            ]);
        });
    }
}
