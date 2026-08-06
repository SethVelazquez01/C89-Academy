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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChangeMembershipRole
{
    /**
     * Change an organization role without allowing privilege escalation.
     */
    public function handle(
        User $actor,
        Team $team,
        User $targetUser,
        string $role,
    ): Membership {
        $validated = validator(['role' => $role], [
            'role' => ['required', 'string', Rule::in(TeamRole::assignableValues())],
        ])->validate();

        $newRole = TeamRole::from((string) $validated['role']);

        return DB::transaction(function () use ($actor, $team, $targetUser, $newRole): Membership {
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

            Gate::forUser($actor)->authorize('updateRole', $membership);

            if ($membership->role === $newRole) {
                return $membership;
            }

            if ($membership->status === MembershipStatus::Active
                && in_array($membership->role, [TeamRole::Owner, TeamRole::Admin], true)
                && $newRole === TeamRole::Member) {
                $activeAdministrators = $memberships
                    ->filter(fn (Membership $candidate): bool => $candidate->status === MembershipStatus::Active)
                    ->filter(fn (Membership $candidate): bool => in_array(
                        $candidate->role,
                        [TeamRole::Owner, TeamRole::Admin],
                        true,
                    ));

                if ($activeAdministrators->count() <= 1) {
                    throw ValidationException::withMessages([
                        'membership' => [__('The last active administrator cannot be demoted.')],
                    ]);
                }
            }

            $membership->update([
                'role' => $newRole,
                'role_changed_by' => $actor->id,
                'role_changed_at' => now(),
            ]);

            return $membership->refresh();
        });
    }
}
