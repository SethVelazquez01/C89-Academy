<?php

namespace App\Actions\Teams;

use App\Enums\MembershipStatus;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RemoveMembership
{
    /**
     * Remove a membership without deleting the global user identity or audit row.
     */
    public function handle(User $actor, Team $team, User $targetUser): Membership
    {
        return DB::transaction(function () use ($actor, $team, $targetUser): Membership {
            $lockedTeam = Team::query()->lockForUpdate()->findOrFail($team->id);

            $memberships = Membership::query()
                ->where('team_id', $lockedTeam->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $membership = $memberships->first(
                fn (Membership $candidate): bool => $candidate->user_id === $targetUser->id,
            );

            if (! $membership instanceof Membership) {
                throw (new ModelNotFoundException)->setModel(Membership::class);
            }

            Gate::forUser($actor)->authorize('remove', $membership);

            if (in_array($membership->role, [TeamRole::Owner, TeamRole::Admin], true)) {
                $administrators = $memberships
                    ->reject(fn (Membership $candidate): bool => $candidate->status === MembershipStatus::Removed)
                    ->filter(fn (Membership $candidate): bool => in_array(
                        $candidate->role,
                        [TeamRole::Owner, TeamRole::Admin],
                        true,
                    ));

                if ($administrators->count() <= 1) {
                    throw ValidationException::withMessages([
                        'membership' => [__('The last organization administrator cannot be removed.')],
                    ]);
                }
            }

            $membership->update([
                'status' => MembershipStatus::Removed,
                'status_changed_by' => $actor->id,
                'status_changed_at' => now(),
            ]);

            if ($targetUser->isCurrentTeam($lockedTeam)) {
                $fallbackTeam = $targetUser->fallbackTeam($lockedTeam);

                if ($fallbackTeam) {
                    $targetUser->switchTeam($fallbackTeam);
                } else {
                    $targetUser->forceFill(['current_team_id' => null])->save();
                    $targetUser->unsetRelation('currentTeam');
                }
            }

            return $membership->refresh();
        });
    }
}
