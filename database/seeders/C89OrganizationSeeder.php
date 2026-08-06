<?php

namespace Database\Seeders;

use App\Actions\Teams\CreateTeam;
use App\Enums\MembershipStatus;
use App\Enums\PlatformRole;
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

        $platformOwner = User::query()->firstOrCreate(
            ['email' => 'owner@c89.com.mx'],
            [
                'name' => 'Owner C89 Academy',
                'password' => Hash::make('password'),
            ],
        );

        $platformOwner->forceFill([
            'email_verified_at' => $platformOwner->email_verified_at ?? now(),
            'platform_role' => PlatformRole::Owner,
        ])->save();

        if ($platformOwner->personalTeam() === null) {
            app(CreateTeam::class)->handle(
                $platformOwner,
                $platformOwner->name."'s Team",
                isPersonal: true,
            );
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
            $administrator->id => [
                'role' => TeamRole::Admin->value,
                'status' => MembershipStatus::Active->value,
                'created_by' => $platformOwner->id,
            ],
            $collaborator->id => [
                'role' => TeamRole::Member->value,
                'status' => MembershipStatus::Active->value,
                'created_by' => $platformOwner->id,
            ],
        ]);

        $administrator->switchTeam($team);
        $collaborator->switchTeam($team);
    }
}
