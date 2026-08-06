<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateOrganization
{
    /**
     * Update a real organization as the global platform owner.
     */
    public function handle(User $actor, Team $organization, string $name): Team
    {
        Gate::forUser($actor)->authorize('updateGlobally', $organization);

        return DB::transaction(function () use ($organization, $name): Team {
            $organization->update(['name' => $name]);

            return $organization->refresh();
        });
    }
}
