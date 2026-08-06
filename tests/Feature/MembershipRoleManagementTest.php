<?php

use App\Actions\Teams\ChangeMembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('an organization admin promotes another collaborator and records the role audit', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);

    $membership = app(ChangeMembershipRole::class)->handle(
        $administrator,
        $team,
        $collaborator,
        TeamRole::Admin->value,
    );

    expect($membership->role)->toBe(TeamRole::Admin)
        ->and($membership->role_changed_by)->toBe($administrator->id)
        ->and($membership->role_changed_at)->not->toBeNull();
});

test('an admin can demote another admin while retaining an active administrator', function () {
    $administrator = User::factory()->create();
    $secondAdministrator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($secondAdministrator, ['role' => TeamRole::Admin->value]);

    $membership = app(ChangeMembershipRole::class)->handle(
        $administrator,
        $team,
        $secondAdministrator,
        TeamRole::Member->value,
    );

    expect($membership->role)->toBe(TeamRole::Member)
        ->and($administrator->fresh()->teamRole($team))->toBe(TeamRole::Admin);
});

test('admins cannot change their own role or a membership from another organization', function () {
    $administrator = User::factory()->create();
    $otherAdministrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);
    $otherTeam->members()->attach($otherAdministrator, ['role' => TeamRole::Admin->value]);

    expect(fn () => app(ChangeMembershipRole::class)->handle(
        $administrator,
        $team,
        $administrator,
        TeamRole::Member->value,
    ))->toThrow(AuthorizationException::class);

    expect(fn () => app(ChangeMembershipRole::class)->handle(
        $otherAdministrator,
        $team,
        $collaborator,
        TeamRole::Admin->value,
    ))->toThrow(AuthorizationException::class);
});

test('organization role management cannot grant the legacy owner role', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);

    expect(fn () => app(ChangeMembershipRole::class)->handle(
        $administrator,
        $team,
        $collaborator,
        TeamRole::Owner->value,
    ))->toThrow(ValidationException::class);

    expect($collaborator->fresh()->teamRole($team))->toBe(TeamRole::Member);
});

test('the legacy tenant owner membership cannot be changed through member management', function () {
    $firstOwner = User::factory()->create();
    $secondOwner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($firstOwner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($secondOwner, ['role' => TeamRole::Owner->value]);

    expect(fn () => app(ChangeMembershipRole::class)->handle(
        $firstOwner,
        $team,
        $secondOwner,
        TeamRole::Admin->value,
    ))->toThrow(AuthorizationException::class);
});

test('removed memberships cannot have their role changed', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, [
        'role' => TeamRole::Member->value,
        'status' => MembershipStatus::Removed->value,
    ]);

    expect(fn () => app(ChangeMembershipRole::class)->handle(
        $administrator,
        $team,
        $collaborator,
        TeamRole::Admin->value,
    ))->toThrow(AuthorizationException::class);
});

test('an admin changes another members role from the organization interface', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);

    Livewire::actingAs($administrator)
        ->test('pages::teams.edit', ['team' => $team])
        ->call('updateMember', $collaborator->id, TeamRole::Admin->value)
        ->assertHasNoErrors();

    $membership = Membership::query()
        ->where('team_id', $team->id)
        ->where('user_id', $collaborator->id)
        ->firstOrFail();

    expect($membership->role)->toBe(TeamRole::Admin)
        ->and($membership->role_changed_by)->toBe($administrator->id);
});

test('returning members record a role change when accepting a different invited role', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create(['email' => 'returning-role@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, [
        'role' => TeamRole::Member->value,
        'status' => MembershipStatus::Removed->value,
    ]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $collaborator->email,
        'role' => TeamRole::Admin,
        'invited_by' => $administrator->id,
    ]);

    Livewire::actingAs($collaborator)
        ->test('pages::teams.pending-invitations-modal')
        ->call('acceptInvitation', $invitation->code)
        ->assertHasNoErrors();

    $membership = Membership::query()
        ->where('team_id', $team->id)
        ->where('user_id', $collaborator->id)
        ->firstOrFail();

    expect($membership->status)->toBe(MembershipStatus::Active)
        ->and($membership->role)->toBe(TeamRole::Admin)
        ->and($membership->role_changed_by)->toBe($administrator->id)
        ->and($membership->role_changed_at)->not->toBeNull();
});
