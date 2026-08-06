<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\MembershipStatus;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Livewire\Livewire;

test('memberships default to the active lifecycle status', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    $membership = Membership::query()
        ->where('team_id', $team->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($membership->status)->toBe(MembershipStatus::Active)
        ->and($membership->isActive())->toBeTrue()
        ->and($membership->grantsAccess())->toBeTrue()
        ->and($membership->consumesSeat())->toBeTrue()
        ->and($membership->created_by)->toBeNull();
});

test('membership lifecycle states expose their access and seat semantics', function () {
    expect(MembershipStatus::Active->grantsAccess())->toBeTrue()
        ->and(MembershipStatus::Active->consumesSeat())->toBeTrue()
        ->and(MembershipStatus::Suspended->grantsAccess())->toBeFalse()
        ->and(MembershipStatus::Suspended->consumesSeat())->toBeTrue()
        ->and(MembershipStatus::Removed->grantsAccess())->toBeFalse()
        ->and(MembershipStatus::Removed->consumesSeat())->toBeFalse();
});

test('personal compatibility membership creation is attributed to the user', function () {
    $user = User::query()->create([
        'name' => 'Usuario auditado',
        'email' => 'auditado@example.com',
        'password' => 'password',
    ]);

    $team = app(CreateTeam::class)->handle(
        $user,
        'Equipo personal auditado',
        isPersonal: true,
    );

    $membership = $team->memberships()->where('user_id', $user->id)->firstOrFail();

    expect($membership->status)->toBe(MembershipStatus::Active)
        ->and($membership->created_by)->toBe($user->id)
        ->and($membership->creator?->is($user))->toBeTrue();
});

test('accepting an invitation records its inviter as membership creator', function () {
    $inviter = User::factory()->create();
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($inviter, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $invitee->email,
        'role' => TeamRole::Member,
        'invited_by' => $inviter->id,
    ]);

    Livewire::actingAs($invitee)
        ->test('pages::teams.pending-invitations-modal')
        ->call('acceptInvitation', $invitation->code)
        ->assertHasNoErrors();

    $membership = $team->memberships()->where('user_id', $invitee->id)->firstOrFail();

    expect($membership->status)->toBe(MembershipStatus::Active)
        ->and($membership->created_by)->toBe($inviter->id)
        ->and($membership->status_changed_by)->toBeNull()
        ->and($membership->status_changed_at)->toBeNull();
});
