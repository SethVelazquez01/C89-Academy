<?php

namespace Database\Seeders;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class C89OrganizationSeeder extends Seeder
{
    /**
     * Seed the C89 organization and its development users.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $team = Team::query()
            ->withTrashed()
            ->firstOrCreate(
                ['slug' => 'c89'],
                ['name' => 'C89', 'is_personal' => false],
            );

        if ($team->trashed()) {
            $team->restore();
        }

        $team->update([
            'name' => 'C89',
            'is_personal' => false,
        ]);

        $administrator = User::query()->firstOrCreate(
            ['email' => 'admin@c89.com.mx'],
            [
                'name' => 'Administrador C89',
                'password' => Hash::make('password'),
            ],
        );

        $administrator->forceFill([
            'email_verified_at' => $administrator->email_verified_at ?? now(),
        ])->save();

        $collaborator = User::query()->firstOrCreate(
            ['email' => 'colaborador@c89.com.mx'],
            [
                'name' => 'Colaborador C89',
                'password' => Hash::make('password'),
            ],
        );

        $collaborator->forceFill([
            'email_verified_at' => $collaborator->email_verified_at ?? now(),
        ])->save();

        $team->members()->syncWithoutDetaching([
            $administrator->id => ['role' => TeamRole::Admin->value],
            $collaborator->id => ['role' => TeamRole::Member->value],
        ]);

        $administrator->switchTeam($team);
        $collaborator->switchTeam($team);
    }
}
