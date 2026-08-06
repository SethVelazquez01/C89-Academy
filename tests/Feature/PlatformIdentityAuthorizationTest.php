<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\PlatformRole;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Database\Seeders\C89OrganizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

test('the global platform role is cast separately from organization membership', function () {
    $platformOwner = User::factory()->platformOwner()->create();
    $organization = Team::factory()->create();

    expect($platformOwner->platform_role)->toBe(PlatformRole::Owner)
        ->and($platformOwner->isPlatformOwner())->toBeTrue()
        ->and($platformOwner->belongsToTeam($organization))->toBeFalse()
        ->and(Gate::forUser($platformOwner)->allows('view', $organization))->toBeFalse();
});

test('only the platform owner can create a real organization', function () {
    $platformOwner = User::factory()->platformOwner()->create();
    $currentTeamId = $platformOwner->current_team_id;

    $organization = app(CreateTeam::class)->handle(
        $platformOwner,
        'Empresa autorizada',
    );

    expect($organization->is_personal)->toBeFalse()
        ->and($platformOwner->fresh()->belongsToTeam($organization))->toBeFalse()
        ->and($platformOwner->fresh()->current_team_id)->toBe($currentTeamId);
});

test('organization admins and collaborators cannot create organizations', function () {
    $this->seed(C89OrganizationSeeder::class);

    $administrator = User::query()->where('email', 'admin@c89.com.mx')->firstOrFail();
    $collaborator = User::query()->where('email', 'colaborador@c89.com.mx')->firstOrFail();

    foreach ([$administrator, $collaborator] as $user) {
        expect(fn () => app(CreateTeam::class)->handle(
            $user,
            'Empresa no autorizada',
        ))->toThrow(AuthorizationException::class);
    }

    expect(Team::query()->where('name', 'Empresa no autorizada')->exists())->toBeFalse();
});

test('the create organization action remains protected when Livewire is manipulated', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::teams.index')
        ->set('name', 'Empresa manipulada')
        ->call('createTeam')
        ->assertForbidden();

    expect(Team::query()->where('name', 'Empresa manipulada')->exists())->toBeFalse();
});

test('personal teams remain available only as a one-time compatibility flow', function () {
    $user = User::query()->create([
        'name' => 'Nuevo usuario',
        'email' => 'nuevo@example.com',
        'password' => 'password',
    ]);

    $personalTeam = app(CreateTeam::class)->handle(
        $user,
        'Equipo personal',
        isPersonal: true,
    );

    expect($personalTeam->is_personal)->toBeTrue()
        ->and($user->fresh()->belongsToTeam($personalTeam))->toBeTrue()
        ->and($user->fresh()->teamRole($personalTeam))->toBe(TeamRole::Owner);

    expect(fn () => app(CreateTeam::class)->handle(
        $user->fresh(),
        'Segundo equipo personal',
        isPersonal: true,
    ))->toThrow(AuthorizationException::class);
});

test('organization admins and collaborators cannot receive the global role through mass assignment', function () {
    $this->seed(C89OrganizationSeeder::class);

    $users = User::query()
        ->whereIn('email', ['admin@c89.com.mx', 'colaborador@c89.com.mx'])
        ->get();

    expect($users)->toHaveCount(2);

    foreach ($users as $user) {
        $user->update(['platform_role' => PlatformRole::Owner->value]);

        expect($user->refresh()->platform_role)->toBeNull()
            ->and($user->isPlatformOwner())->toBeFalse();
    }
});

test('organization invitations reject the legacy owner role from manipulated input', function () {
    $this->seed(C89OrganizationSeeder::class);

    $team = Team::query()->where('slug', 'c89')->firstOrFail();
    $administrator = User::query()->where('email', 'admin@c89.com.mx')->firstOrFail();

    Livewire::actingAs($administrator)
        ->test('pages::teams.invite-member-modal', ['team' => $team])
        ->set('inviteEmail', 'nuevo-admin@example.com')
        ->set('inviteRole', TeamRole::Owner->value)
        ->call('createInvitation')
        ->assertHasErrors(['inviteRole']);

    $this->assertDatabaseMissing('team_invitations', [
        'team_id' => $team->id,
        'email' => 'nuevo-admin@example.com',
    ]);
});

test('organization member updates reject the legacy owner role from manipulated input', function () {
    $tenantOwner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($tenantOwner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    Livewire::actingAs($tenantOwner)
        ->test('pages::teams.edit', ['team' => $team])
        ->call('updateMember', $member->id, TeamRole::Owner->value)
        ->assertHasErrors(['role']);

    expect($member->fresh()->teamRole($team))->toBe(TeamRole::Member);
});

test('an existing invitation with the legacy owner role cannot be accepted', function () {
    $tenantOwner = User::factory()->create();
    $invitee = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($tenantOwner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::query()->create([
        'team_id' => $team->id,
        'email' => $invitee->email,
        'role' => TeamRole::Owner,
        'invited_by' => $tenantOwner->id,
    ]);

    Livewire::actingAs($invitee)
        ->test('pages::teams.pending-invitations-modal')
        ->call('acceptInvitation', $invitation->code)
        ->assertHasErrors(['invitation']);

    expect($invitee->fresh()->belongsToTeam($team))->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

test('organization creation controls are hidden from regular users', function () {
    $regularUser = User::factory()->create();
    $platformOwner = User::factory()->platformOwner()->create();

    Livewire::actingAs($regularUser)
        ->test('pages::teams.index')
        ->assertDontSeeHtml('data-test="teams-new-team-button"');

    Livewire::actingAs($platformOwner)
        ->test('pages::teams.index')
        ->assertSeeHtml('data-test="teams-new-team-button"');
});
