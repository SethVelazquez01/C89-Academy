<?php

namespace App\Actions\Teams;

use App\Enums\OrganizationSeatType;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Rules\UniqueTeamInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateTeamInvitation
{
    /**
     * Create an invitation while atomically reserving its organization seat.
     */
    public function handle(User $actor, Team $team, string $email, string $role): TeamInvitation
    {
        return DB::transaction(function () use ($actor, $team, $email, $role): TeamInvitation {
            $lockedTeam = Team::query()->lockForUpdate()->findOrFail($team->id);

            Gate::forUser($actor)->authorize('inviteMember', $lockedTeam);

            $validated = validator([
                'email' => Str::lower(trim($email)),
                'role' => $role,
            ], [
                'email' => ['required', 'string', 'email', 'max:255', new UniqueTeamInvitation($lockedTeam)],
                'role' => ['required', 'string', Rule::in(TeamRole::assignableValues())],
            ])->validate();

            $teamRole = TeamRole::from((string) $validated['role']);
            $seatType = OrganizationSeatType::fromRole($teamRole);

            if (! $lockedTeam->hasAvailableSeat($seatType)) {
                throw ValidationException::withMessages([
                    'inviteRole' => [__('There are no available :seatType seats in this organization.', [
                        'seatType' => strtolower($seatType->label()),
                    ])],
                ]);
            }

            return $lockedTeam->invitations()->create([
                'email' => $validated['email'],
                'role' => $teamRole,
                'invited_by' => $actor->id,
                'expires_at' => now()->addDays(3),
            ]);
        });
    }
}
