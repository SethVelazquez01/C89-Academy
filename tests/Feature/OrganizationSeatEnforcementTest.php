<?php

use App\Actions\Teams\AcceptTeamInvitation;
use App\Actions\Teams\ChangeMembershipRole;
use App\Actions\Teams\CreateTeamInvitation;
use App\Actions\Teams\InviteInitialOrganizationAdministrator;
use App\Actions\Teams\RemoveMembership;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationSeatType;
use App\Enums\PlatformRole;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('an active membership prevents reserving a seat beyond collaborator capacity', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create([
        'included_collaborator_seats' => 1,
    ]);

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);

    expect(fn () => app(CreateTeamInvitation::class)->handle(
        $administrator,
        $team,
        'new-collaborator@example.com',
        TeamRole::Member->value,
    ))->toThrow(ValidationException::class);

    $this->assertDatabaseMissing('team_invitations', [
        'email' => 'new-collaborator@example.com',
    ]);
});

test('a suspended membership continues consuming its seat', function () {
    $administrator = User::factory()->create();
    $suspendedCollaborator = User::factory()->create();
    $team = Team::factory()->create([
        'included_collaborator_seats' => 1,
    ]);

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($suspendedCollaborator, [
        'role' => TeamRole::Member->value,
        'status' => MembershipStatus::Suspended->value,
    ]);

    expect(fn () => app(CreateTeamInvitation::class)->handle(
        $administrator,
        $team,
        'replacement@example.com',
        TeamRole::Member->value,
    ))->toThrow(ValidationException::class);
});

test('a pending invitation prevents reserving the same last seat twice', function () {
    $administrator = User::factory()->create();
    $team = Team::factory()->create([
        'included_collaborator_seats' => 1,
    ]);

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    app(CreateTeamInvitation::class)->handle(
        $administrator,
        $team,
        'first@example.com',
        TeamRole::Member->value,
    );

    expect(fn () => app(CreateTeamInvitation::class)->handle(
        $administrator,
        $team,
        'second@example.com',
        TeamRole::Member->value,
    ))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('team_invitations', 1);
});

test('additional seats expand capacity and keep role pools separate', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create([
        'included_administrator_seats' => 2,
        'additional_administrator_seats' => 0,
        'included_collaborator_seats' => 1,
        'additional_collaborator_seats' => 1,
    ]);

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);

    $administratorInvitation = app(CreateTeamInvitation::class)->handle(
        $administrator,
        $team,
        'second-admin@example.com',
        TeamRole::Admin->value,
    );
    $collaboratorInvitation = app(CreateTeamInvitation::class)->handle(
        $administrator,
        $team,
        'second-collaborator@example.com',
        TeamRole::Member->value,
    );

    expect($administratorInvitation)->toBeInstanceOf(TeamInvitation::class)
        ->and($collaboratorInvitation)->toBeInstanceOf(TeamInvitation::class);
});

test('role changes cannot exceed the destination seat capacity', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create([
        'included_administrator_seats' => 1,
        'included_collaborator_seats' => 1,
    ]);

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);

    expect(fn () => app(ChangeMembershipRole::class)->handle(
        $administrator,
        $team,
        $collaborator,
        TeamRole::Admin->value,
    ))->toThrow(ValidationException::class);

    expect($collaborator->fresh()->teamRole($team))->toBe(TeamRole::Member);
});

test('additional destination capacity allows a role change', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create([
        'included_administrator_seats' => 1,
        'additional_administrator_seats' => 1,
    ]);

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);

    $membership = app(ChangeMembershipRole::class)->handle(
        $administrator,
        $team,
        $collaborator,
        TeamRole::Admin->value,
    );

    expect($membership->role)->toBe(TeamRole::Admin);
});

test('accepting an invitation transfers its reservation without increasing usage', function () {
    $administrator = User::factory()->create();
    $invitee = User::factory()->create(['email' => 'reserved@example.com']);
    $team = Team::factory()->create([
        'included_collaborator_seats' => 1,
    ]);

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    $invitation = app(CreateTeamInvitation::class)->handle(
        $administrator,
        $team,
        $invitee->email,
        TeamRole::Member->value,
    );

    expect($team->occupiedSeats(OrganizationSeatType::Collaborator))->toBe(1);

    app(AcceptTeamInvitation::class)->handle($invitee, $invitation->code);

    $membership = Membership::query()
        ->where('team_id', $team->id)
        ->where('user_id', $invitee->id)
        ->firstOrFail();

    expect($invitation->fresh()->isAccepted())->toBeTrue()
        ->and($membership->status)->toBe(MembershipStatus::Active)
        ->and($team->occupiedSeats(OrganizationSeatType::Collaborator))->toBe(1);
});

test('the platform owner cannot reserve an initial administrator beyond capacity', function () {
    $platformOwner = User::factory()->create([
        'platform_role' => PlatformRole::Owner,
    ]);
    $team = Team::factory()->create([
        'included_administrator_seats' => 0,
    ]);

    expect(fn () => app(InviteInitialOrganizationAdministrator::class)->handle(
        $platformOwner,
        $team,
        'administrator@example.com',
    ))->toThrow(ValidationException::class);

    $this->assertDatabaseMissing('team_invitations', [
        'team_id' => $team->id,
        'email' => 'administrator@example.com',
    ]);
});

test('cancelling a pending invitation releases its seat', function () {
    $administrator = User::factory()->create();
    $team = Team::factory()->create([
        'included_collaborator_seats' => 1,
    ]);

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    $invitation = app(CreateTeamInvitation::class)->handle(
        $administrator,
        $team,
        'cancelled@example.com',
        TeamRole::Member->value,
    );

    Livewire::actingAs($administrator)
        ->test('pages::teams.cancel-invitation-modal', ['team' => $team])
        ->set('invitationCode', $invitation->code)
        ->call('cancelInvitation')
        ->assertHasNoErrors();

    expect($team->occupiedSeats(OrganizationSeatType::Collaborator))->toBe(0)
        ->and($team->hasAvailableSeat(OrganizationSeatType::Collaborator))->toBeTrue();
});

test('declining a pending invitation releases its seat', function () {
    $administrator = User::factory()->create();
    $invitee = User::factory()->create(['email' => 'declined@example.com']);
    $team = Team::factory()->create([
        'included_collaborator_seats' => 1,
    ]);

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    $invitation = app(CreateTeamInvitation::class)->handle(
        $administrator,
        $team,
        $invitee->email,
        TeamRole::Member->value,
    );

    Livewire::actingAs($invitee)
        ->test('pages::teams.pending-invitations-modal')
        ->call('declineInvitation', $invitation->code)
        ->assertHasNoErrors();

    expect($team->occupiedSeats(OrganizationSeatType::Collaborator))->toBe(0)
        ->and($team->hasAvailableSeat(OrganizationSeatType::Collaborator))->toBeTrue();
});

test('removing a membership releases its seat while suspension does not', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create([
        'included_collaborator_seats' => 1,
    ]);

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, [
        'role' => TeamRole::Member->value,
        'status' => MembershipStatus::Suspended->value,
    ]);

    expect($team->occupiedSeats(OrganizationSeatType::Collaborator))->toBe(1);

    app(RemoveMembership::class)->handle($administrator, $team, $collaborator);

    expect($team->occupiedSeats(OrganizationSeatType::Collaborator))->toBe(0)
        ->and($team->hasAvailableSeat(OrganizationSeatType::Collaborator))->toBeTrue();
});
