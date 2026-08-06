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

class ChangeMembershipStatus
{
    /**
     * Suspend or reactivate an organization membership safely.
     */
    public function handle(
        User $actor,
        Team $team,
        User $targetUser,
        MembershipStatus $status,
    ): Membership {
        if (! in_array($status, [MembershipStatus::Active, MembershipStatus::Suspended], true)) {
            throw ValidationException::withMessages([
                'membership' => [__('This membership status transition is not supported.')],
            ]);
        }

        return DB::transaction(function () use ($actor, $team, $targetUser, $status): Membership {
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

            Gate::forUser($actor)->authorize(
                $status === MembershipStatus::Suspended ? 'suspend' : 'reactivate',
                $membership,
            );

            if ($status === MembershipStatus::Suspended
                && in_array($membership->role, [TeamRole::Owner, TeamRole::Admin], true)) {
                $activeAdministrators = $memberships
                    ->filter(fn (Membership $candidate): bool => $candidate->status === MembershipStatus::Active)
                    ->filter(fn (Membership $candidate): bool => in_array(
                        $candidate->role,
                        [TeamRole::Owner, TeamRole::Admin],
                        true,
                    ));

                if ($activeAdministrators->count() <= 1) {
                    throw ValidationException::withMessages([
                        'membership' => [__('The last active administrator cannot be suspended.')],
                    ]);
                }
            }

            $membership->update([
                'status' => $status,
                'status_changed_by' => $actor->id,
                'status_changed_at' => now(),
            ]);

            if ($status === MembershipStatus::Suspended && $targetUser->isCurrentTeam($lockedTeam)) {
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
