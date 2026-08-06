<?php

use App\Actions\Teams\RemoveMembership;
use App\Enums\MembershipStatus;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

test('an organization admin removes a collaborator without deleting the global identity or membership audit row', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);
    $collaborator->switchTeam($team);

    $membership = Membership::query()
        ->where('team_id', $team->id)
        ->where('user_id', $collaborator->id)
        ->firstOrFail();

    $removedMembership = app(RemoveMembership::class)->handle(
        $administrator,
        $team,
        $collaborator,
    );

    expect($removedMembership->id)->toBe($membership->id)
        ->and($removedMembership->status)->toBe(MembershipStatus::Removed)
        ->and($removedMembership->status_changed_by)->toBe($administrator->id)
        ->and($removedMembership->status_changed_at)->not->toBeNull()
        ->and($removedMembership->consumesSeat())->toBeFalse()
        ->and($collaborator->fresh())->not->toBeNull()
        ->and($collaborator->fresh()->belongsToTeam($team))->toBeFalse()
        ->and($collaborator->fresh()->current_team_id)->not->toBe($team->id)
        ->and($team->fresh()->members()->whereKey($collaborator->id)->exists())->toBeFalse();

    $this->assertDatabaseHas('team_members', [
        'id' => $membership->id,
        'status' => MembershipStatus::Removed->value,
    ]);
    $this->assertDatabaseHas('users', ['id' => $collaborator->id]);
});

test('a suspended collaborator can be removed and releases their seat', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, [
        'role' => TeamRole::Member->value,
        'status' => MembershipStatus::Suspended->value,
    ]);

    $membership = app(RemoveMembership::class)->handle(
        $administrator,
        $team,
        $collaborator,
    );

    expect($membership->status)->toBe(MembershipStatus::Removed)
        ->and($membership->consumesSeat())->toBeFalse();
});

test('administrators cannot remove themselves or members from another organization', function () {
    $administrator = User::factory()->create();
    $otherAdministrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);
    $otherTeam->members()->attach($otherAdministrator, ['role' => TeamRole::Admin->value]);

    expect(fn () => app(RemoveMembership::class)->handle(
        $administrator,
        $team,
        $administrator,
    ))->toThrow(AuthorizationException::class);

    expect(fn () => app(RemoveMembership::class)->handle(
        $otherAdministrator,
        $team,
        $collaborator,
    ))->toThrow(AuthorizationException::class);

    expect($collaborator->fresh()->belongsToTeam($team))->toBeTrue();
});

test('a removed user can accept a new invitation and reuse the audited membership row', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create(['email' => 'returning@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);

    $removedMembership = app(RemoveMembership::class)->handle(
        $administrator,
        $team,
        $collaborator,
    );

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $collaborator->email,
        'role' => TeamRole::Member,
        'invited_by' => $administrator->id,
    ]);

    Livewire::actingAs($collaborator)
        ->test('pages::teams.pending-invitations-modal')
        ->call('acceptInvitation', $invitation->code)
        ->assertHasNoErrors();

    $restoredMembership = Membership::query()->findOrFail($removedMembership->id);

    expect($restoredMembership->status)->toBe(MembershipStatus::Active)
        ->and($restoredMembership->status_changed_by)->toBe($administrator->id)
        ->and($collaborator->fresh()->belongsToTeam($team))->toBeTrue()
        ->and(Membership::query()
            ->where('team_id', $team->id)
            ->where('user_id', $collaborator->id)
            ->count())->toBe(1);
});

test('the existing removal modal uses auditable membership removal for tenant admins', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);

    Livewire::actingAs($administrator)
        ->test('pages::teams.remove-member-modal', ['team' => $team])
        ->set('memberId', $collaborator->id)
        ->call('removeMember')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('team_members', [
        'team_id' => $team->id,
        'user_id' => $collaborator->id,
        'status' => MembershipStatus::Removed->value,
    ]);
    $this->assertDatabaseHas('users', ['id' => $collaborator->id]);
});
