<?php

namespace App\Actions\Teams;

use App\Enums\MembershipStatus;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateTeam
{
    /**
     * Create a real organization or the user's compatibility personal team.
     */
    public function handle(
        User $user,
        string $name,
        bool $isPersonal = false,
        int $includedAdministratorSeats = 1,
        int $includedCollaboratorSeats = 1,
    ): Team {
        Gate::forUser($user)->authorize(
            $isPersonal ? 'createPersonal' : 'create',
            Team::class,
        );

        $validated = validator([
            'included_administrator_seats' => $includedAdministratorSeats,
            'included_collaborator_seats' => $includedCollaboratorSeats,
        ], [
            'included_administrator_seats' => ['required', 'integer', 'min:1', 'max:100000'],
            'included_collaborator_seats' => ['required', 'integer', 'min:0', 'max:100000'],
        ])->validate();

        return DB::transaction(function () use ($user, $name, $isPersonal, $validated): Team {
            $team = Team::query()->create([
                'name' => $name,
                'is_personal' => $isPersonal,
                'included_administrator_seats' => $validated['included_administrator_seats'],
                'included_collaborator_seats' => $validated['included_collaborator_seats'],
            ]);

            if ($isPersonal) {
                $team->memberships()->create([
                    'user_id' => $user->id,
                    'role' => TeamRole::Owner,
                    'status' => MembershipStatus::Active,
                    'created_by' => $user->id,
                ]);

                $user->switchTeam($team);
            }

            return $team;
        });
    }
}
