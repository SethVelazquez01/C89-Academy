<?php

namespace App\Actions\Teams;

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
    public function handle(User $user, string $name, bool $isPersonal = false): Team
    {
        Gate::forUser($user)->authorize(
            $isPersonal ? 'createPersonal' : 'create',
            Team::class,
        );

        return DB::transaction(function () use ($user, $name, $isPersonal): Team {
            $team = Team::query()->create([
                'name' => $name,
                'is_personal' => $isPersonal,
            ]);

            if ($isPersonal) {
                $team->memberships()->create([
                    'user_id' => $user->id,
                    'role' => TeamRole::Owner,
                ]);

                $user->switchTeam($team);
            }

            return $team;
        });
    }
}
