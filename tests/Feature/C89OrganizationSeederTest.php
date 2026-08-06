<?php

use App\Enums\PlatformRole;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\C89OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it creates the C89 organization and development users without duplicates', function () {
    $this->seed(C89OrganizationSeeder::class);
    $this->seed(C89OrganizationSeeder::class);

    $team = Team::query()->where('slug', 'c89')->firstOrFail();
    $platformOwner = User::query()->where('email', 'owner@c89.com.mx')->firstOrFail();
    $personalTeam = $platformOwner->teams()->where('is_personal', true)->firstOrFail();
    $administrator = User::query()->where('email', 'admin@c89.com.mx')->firstOrFail();
    $collaborator = User::query()->where('email', 'colaborador@c89.com.mx')->firstOrFail();

    expect($platformOwner->platform_role)->toBe(PlatformRole::Owner)
        ->and($platformOwner->isPlatformOwner())->toBeTrue()
        ->and($platformOwner->belongsToTeam($team))->toBeFalse()
        ->and($platformOwner->current_team_id)->toBe($personalTeam->id)
        ->and($personalTeam->is_personal)->toBeTrue()
        ->and($platformOwner->teamRole($personalTeam))->toBe(TeamRole::Owner)
        ->and($team->name)->toBe('C89')
        ->and($team->is_personal)->toBeFalse()
        ->and($team->members()->count())->toBe(2)
        ->and($administrator->teamRole($team))->toBe(TeamRole::Admin)
        ->and($collaborator->teamRole($team))->toBe(TeamRole::Member)
        ->and($administrator->email_verified_at)->not->toBeNull()
        ->and($collaborator->email_verified_at)->not->toBeNull()
        ->and($administrator->current_team_id)->toBe($team->id)
        ->and($collaborator->current_team_id)->toBe($team->id);

    $this->assertDatabaseCount('teams', 2);
    $this->assertDatabaseCount('users', 3);
    $this->assertDatabaseCount('team_members', 3);
});
