<?php

namespace App\Actions\Teams;

use App\Enums\MembershipStatus;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AcceptTeamInvitation
{
    /**
     * Convert a reserved invitation seat into an active membership.
     */
    public function handle(User $user, string $code): Team
    {
        return DB::transaction(function () use ($user, $code): Team {
            $invitation = TeamInvitation::query()
                ->where('code', $code)
                ->firstOrFail();

            $team = Team::query()
                ->lockForUpdate()
                ->findOrFail($invitation->team_id);

            $lockedInvitation = TeamInvitation::query()
                ->whereKey($invitation->id)
                ->where('team_id', $team->id)
                ->whereNull('accepted_at')
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedInvitation->isExpired()) {
                throw ValidationException::withMessages([
                    'invitation' => [__('This invitation has expired.')],
                ]);
            }

            if (Str::lower($lockedInvitation->email) !== Str::lower($user->email)) {
                throw ValidationException::withMessages([
                    'invitation' => [__('This invitation was sent to a different email address.')],
                ]);
            }

            if (! in_array($lockedInvitation->role->value, TeamRole::assignableValues(), true)) {
                throw ValidationException::withMessages([
                    'invitation' => [__('This invitation contains an invalid role.')],
                ]);
            }

            $membership = Membership::query()
                ->where('team_id', $team->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($membership && ! $membership->isRemoved()) {
                throw ValidationException::withMessages([
                    'invitation' => [__('You already have a current membership in this organization.')],
                ]);
            }

            if ($membership) {
                $updates = [
                    'role' => $lockedInvitation->role,
                    'status' => MembershipStatus::Active,
                    'status_changed_by' => $lockedInvitation->invited_by,
                    'status_changed_at' => now(),
                ];

                if ($membership->role !== $lockedInvitation->role) {
                    $updates['role_changed_by'] = $lockedInvitation->invited_by;
                    $updates['role_changed_at'] = now();
                }

                $membership->update($updates);
            } else {
                $team->memberships()->create([
                    'user_id' => $user->id,
                    'role' => $lockedInvitation->role,
                    'status' => MembershipStatus::Active,
                    'created_by' => $lockedInvitation->invited_by,
                ]);
            }

            $lockedInvitation->update(['accepted_at' => now()]);

            $user->switchTeam($team);

            return $team;
        });
    }
}
