<?php

use App\Enums\MembershipStatus;
use App\Enums\OrganizationSeatType;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Livewire\Livewire;

test('organization capacities keep included and additional seats separate by type', function () {
    $team = Team::factory()->create([
        'included_administrator_seats' => 2,
        'additional_administrator_seats' => 3,
        'included_collaborator_seats' => 5,
        'additional_collaborator_seats' => 7,
    ]);

    expect($team->seatCapacity(OrganizationSeatType::Administrator))->toBe(5)
        ->and($team->seatCapacity(OrganizationSeatType::Collaborator))->toBe(12);
});

test('active and suspended memberships consume the seat for their role', function () {
    $team = Team::factory()->create([
        'included_administrator_seats' => 3,
        'included_collaborator_seats' => 3,
    ]);

    $team->members()->attach(User::factory()->create(), [
        'role' => TeamRole::Admin->value,
        'status' => MembershipStatus::Active->value,
    ]);
    $team->members()->attach(User::factory()->create(), [
        'role' => TeamRole::Member->value,
        'status' => MembershipStatus::Suspended->value,
    ]);
    $team->members()->attach(User::factory()->create(), [
        'role' => TeamRole::Member->value,
        'status' => MembershipStatus::Removed->value,
    ]);

    expect($team->occupiedSeats(OrganizationSeatType::Administrator))->toBe(1)
        ->and($team->occupiedSeats(OrganizationSeatType::Collaborator))->toBe(1)
        ->and($team->availableSeats(OrganizationSeatType::Administrator))->toBe(2)
        ->and($team->availableSeats(OrganizationSeatType::Collaborator))->toBe(2);
});

test('only pending unexpired invitations reserve seats', function () {
    $inviter = User::factory()->create();
    $team = Team::factory()->create([
        'included_collaborator_seats' => 2,
    ]);

    TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'role' => TeamRole::Member,
        'invited_by' => $inviter->id,
    ]);
    TeamInvitation::factory()->accepted()->create([
        'team_id' => $team->id,
        'role' => TeamRole::Member,
        'invited_by' => $inviter->id,
    ]);
    TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'role' => TeamRole::Member,
        'invited_by' => $inviter->id,
    ]);

    expect($team->occupiedSeats(OrganizationSeatType::Collaborator))->toBe(1)
        ->and($team->availableSeats(OrganizationSeatType::Collaborator))->toBe(1)
        ->and($team->hasAvailableSeat(OrganizationSeatType::Collaborator))->toBeTrue();
});

test('legacy owner and admin roles share the administrator seat type', function () {
    expect(OrganizationSeatType::fromRole(TeamRole::Owner))->toBe(OrganizationSeatType::Administrator)
        ->and(OrganizationSeatType::fromRole(TeamRole::Admin))->toBe(OrganizationSeatType::Administrator)
        ->and(OrganizationSeatType::fromRole(TeamRole::Member))->toBe(OrganizationSeatType::Collaborator);
});

test('seat availability is exhausted when reservations reach capacity', function () {
    $inviter = User::factory()->create();
    $team = Team::factory()->create([
        'included_administrator_seats' => 1,
    ]);

    TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'role' => TeamRole::Admin,
        'invited_by' => $inviter->id,
    ]);

    expect($team->availableSeats(OrganizationSeatType::Administrator))->toBe(0)
        ->and($team->hasAvailableSeat(OrganizationSeatType::Administrator))->toBeFalse();
});

test('organization administrators can see seat usage and its capacity breakdown', function () {
    $administrator = User::factory()->create();
    $suspendedCollaborator = User::factory()->create();
    $team = Team::factory()->create([
        'included_administrator_seats' => 2,
        'additional_administrator_seats' => 1,
        'included_collaborator_seats' => 2,
        'additional_collaborator_seats' => 1,
    ]);

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($suspendedCollaborator, [
        'role' => TeamRole::Member->value,
        'status' => MembershipStatus::Suspended->value,
    ]);
    TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'role' => TeamRole::Member,
        'invited_by' => $administrator->id,
    ]);

    Livewire::actingAs($administrator)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertSeeHtml('data-test="organization-seat-summary"')
        ->assertSeeHtml('data-test="seat-summary-administrator"')
        ->assertSeeHtml('data-test="seat-summary-collaborator"')
        ->assertSee('Administradores')
        ->assertSee('Colaboradores')
        ->assertSee('1 of 3 occupied')
        ->assertSee('2 of 3 occupied')
        ->assertSee('Included: 2')
        ->assertSee('Additional: 1');
});
