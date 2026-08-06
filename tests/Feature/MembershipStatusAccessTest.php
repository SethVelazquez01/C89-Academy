<?php

use App\Actions\Teams\ChangeMembershipStatus;
use App\Enums\MembershipStatus;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Course;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

test('an active organization admin can suspend and reactivate a collaborator', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($administrator, [
        'role' => TeamRole::Admin->value,
        'status' => MembershipStatus::Active->value,
    ]);
    $team->members()->attach($collaborator, [
        'role' => TeamRole::Member->value,
        'status' => MembershipStatus::Active->value,
    ]);
    $collaborator->switchTeam($team);

    $suspendedMembership = app(ChangeMembershipStatus::class)->handle(
        $administrator,
        $team,
        $collaborator,
        MembershipStatus::Suspended,
    );

    expect($suspendedMembership->status)->toBe(MembershipStatus::Suspended)
        ->and($suspendedMembership->status_changed_by)->toBe($administrator->id)
        ->and($suspendedMembership->status_changed_at)->not->toBeNull()
        ->and($suspendedMembership->consumesSeat())->toBeTrue()
        ->and($suspendedMembership->grantsAccess())->toBeFalse()
        ->and($collaborator->fresh()->belongsToTeam($team))->toBeFalse()
        ->and($collaborator->fresh()->teamRole($team))->toBeNull()
        ->and($collaborator->fresh()->current_team_id)->not->toBe($team->id);

    $reactivatedMembership = app(ChangeMembershipStatus::class)->handle(
        $administrator,
        $team,
        $collaborator->fresh(),
        MembershipStatus::Active,
    );

    expect($reactivatedMembership->status)->toBe(MembershipStatus::Active)
        ->and($reactivatedMembership->status_changed_by)->toBe($administrator->id)
        ->and($collaborator->fresh()->belongsToTeam($team))->toBeTrue()
        ->and($collaborator->fresh()->teamRole($team))->toBe(TeamRole::Member);
});

test('a suspended membership disappears from accessible team navigation', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Empresa suspendida']);

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);

    app(ChangeMembershipStatus::class)->handle(
        $administrator,
        $team,
        $collaborator,
        MembershipStatus::Suspended,
    );

    expect($collaborator->fresh()->toUserTeams(includeCurrent: true)
        ->contains(fn ($userTeam): bool => $userTeam->id === $team->id))->toBeFalse()
        ->and($team->fresh()->members()->whereKey($collaborator->id)->exists())->toBeTrue();
});

test('a suspended collaborator cannot view courses from the organization', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();
    $course = Course::factory()->published()->create(['team_id' => $team->id]);

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);
    $collaborator->switchTeam($team);

    expect(Gate::forUser($collaborator)->allows('view', $course))->toBeTrue();

    app(ChangeMembershipStatus::class)->handle(
        $administrator,
        $team,
        $collaborator,
        MembershipStatus::Suspended,
    );

    expect(Gate::forUser($collaborator->fresh())->allows('view', $course))->toBeFalse();
});

test('the last active administrator cannot be suspended', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);

    expect(fn () => app(ChangeMembershipStatus::class)->handle(
        $collaborator,
        $team,
        $administrator,
        MembershipStatus::Suspended,
    ))->toThrow(AuthorizationException::class);

    expect(fn () => app(ChangeMembershipStatus::class)->handle(
        $administrator,
        $team,
        $administrator,
        MembershipStatus::Suspended,
    ))->toThrow(AuthorizationException::class);

    $secondAdministrator = User::factory()->create();
    $team->members()->attach($secondAdministrator, ['role' => TeamRole::Admin->value]);

    app(ChangeMembershipStatus::class)->handle(
        $secondAdministrator,
        $team,
        $administrator,
        MembershipStatus::Suspended,
    );

    expect($administrator->fresh()->teamRole($team))->toBeNull();

    expect(fn () => app(ChangeMembershipStatus::class)->handle(
        $secondAdministrator,
        $team,
        $secondAdministrator,
        MembershipStatus::Suspended,
    ))->toThrow(AuthorizationException::class);
});

test('cross organization and global platform actors cannot manage tenant membership status', function () {
    $administrator = User::factory()->create();
    $otherAdministrator = User::factory()->create();
    $platformOwner = User::factory()->platformOwner()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);
    $otherTeam->members()->attach($otherAdministrator, ['role' => TeamRole::Admin->value]);

    foreach ([$otherAdministrator, $platformOwner] as $actor) {
        expect(fn () => app(ChangeMembershipStatus::class)->handle(
            $actor,
            $team,
            $collaborator,
            MembershipStatus::Suspended,
        ))->toThrow(AuthorizationException::class);
    }

    expect($collaborator->fresh()->teamRole($team))->toBe(TeamRole::Member);
});

test('personal and removed memberships cannot enter suspension transitions', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, [
        'role' => TeamRole::Member->value,
        'status' => MembershipStatus::Removed->value,
    ]);

    expect(fn () => app(ChangeMembershipStatus::class)->handle(
        $administrator,
        $team,
        $collaborator,
        MembershipStatus::Active,
    ))->toThrow(AuthorizationException::class);

    $personalTeam = $administrator->teams()->where('is_personal', true)->firstOrFail();

    $personalMembership = Membership::query()
        ->where('team_id', $personalTeam->id)
        ->firstOrFail();

    expect(fn () => app(ChangeMembershipStatus::class)->handle(
        $administrator,
        $personalMembership->team,
        $administrator,
        MembershipStatus::Suspended,
    ))->toThrow(AuthorizationException::class);
});

test('only active administrators receive the membership status permission', function () {
    $administrator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    expect($administrator->hasTeamPermission($team, TeamPermission::ManageMemberStatus))->toBeTrue();

    Membership::query()
        ->where('team_id', $team->id)
        ->where('user_id', $administrator->id)
        ->update(['status' => MembershipStatus::Suspended->value]);

    expect($administrator->fresh()->hasTeamPermission($team, TeamPermission::ManageMemberStatus))->toBeFalse();
});

test('removed is not a supported status for the suspension action', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);

    expect(fn () => app(ChangeMembershipStatus::class)->handle(
        $administrator,
        $team,
        $collaborator,
        MembershipStatus::Removed,
    ))->toThrow(ValidationException::class);
});
