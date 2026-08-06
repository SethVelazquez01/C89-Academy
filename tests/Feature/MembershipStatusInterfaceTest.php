<?php

use App\Enums\MembershipStatus;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('an organization admin sees membership status controls for another active member', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);

    Livewire::actingAs($administrator)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertSee('Activa')
        ->assertSeeHtml('data-test="member-status-badge"')
        ->assertSeeHtml('data-test="member-suspend-button"')
        ->assertDontSeeHtml('data-test="member-reactivate-button"');
});

test('an organization admin can suspend and reactivate a collaborator from the member interface', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);

    $component = Livewire::actingAs($administrator)
        ->test('pages::teams.edit', ['team' => $team])
        ->call('suspendMember', $collaborator->id)
        ->assertHasNoErrors()
        ->assertSee('Suspendida')
        ->assertSeeHtml('data-test="member-reactivate-button"');

    $membership = Membership::query()
        ->where('team_id', $team->id)
        ->where('user_id', $collaborator->id)
        ->firstOrFail();

    expect($membership->status)->toBe(MembershipStatus::Suspended)
        ->and($membership->status_changed_by)->toBe($administrator->id);

    $component
        ->call('reactivateMember', $collaborator->id)
        ->assertHasNoErrors()
        ->assertSee('Activa')
        ->assertSeeHtml('data-test="member-suspend-button"');

    expect($membership->refresh()->status)->toBe(MembershipStatus::Active);
});

test('collaborators cannot manipulate membership status actions', function () {
    $administrator = User::factory()->create();
    $firstCollaborator = User::factory()->create();
    $secondCollaborator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($firstCollaborator, ['role' => TeamRole::Member->value]);
    $team->members()->attach($secondCollaborator, ['role' => TeamRole::Member->value]);

    Livewire::actingAs($firstCollaborator)
        ->test('pages::teams.edit', ['team' => $team])
        ->call('suspendMember', $secondCollaborator->id)
        ->assertForbidden();

    expect($secondCollaborator->fresh()->teamRole($team))->toBe(TeamRole::Member);
});

test('membership status controls are hidden for the acting admin themselves', function () {
    $administrator = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    Livewire::actingAs($administrator)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertSee('Activa')
        ->assertDontSeeHtml('data-test="member-suspend-button"')
        ->assertDontSeeHtml('data-test="member-reactivate-button"');
});
