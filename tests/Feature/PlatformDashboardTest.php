<?php

use App\Enums\MembershipStatus;
use App\Enums\PlatformRole;
use App\Enums\TeamRole;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('allows only the platform owner to open the global dashboard', function () {
    $owner = User::factory()->platformOwner()->create();
    $regularUser = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('platform.dashboard'))
        ->assertOk()
        ->assertSee('Centro de control C89 Academy')
        ->assertSeeHtml('data-test="platform-dashboard"')
        ->assertDontSee('Mi aprendizaje')
        ->assertDontSee($owner->currentTeam->name);

    $this->actingAs($regularUser)
        ->get(route('platform.dashboard'))
        ->assertForbidden();
});

it('redirects the platform owner away from organization learning dashboards', function () {
    $owner = User::factory()->platformOwner()->create();

    $this->actingAs($owner)
        ->get(route('dashboard', ['current_team' => $owner->currentTeam]))
        ->assertRedirect(route('platform.dashboard'));
});

it('keeps the owner organization administration separate from personal teams', function () {
    $owner = User::factory()->platformOwner()->create();
    $organization = Team::factory()->create(['name' => 'Empresa administrable']);

    $this->actingAs($owner)
        ->get(route('teams.index'))
        ->assertOk()
        ->assertSee('Administración global')
        ->assertSee('Empresa administrable')
        ->assertDontSeeHtml('data-test="user-team-memberships"')
        ->assertDontSee($owner->currentTeam->name);
});

it('summarizes organizations users courses lessons and private resource storage', function () {
    $owner = User::factory()->platformOwner()->create();
    $organization = Team::factory()->create([
        'name' => 'Empresa con seguimiento',
        'included_administrator_seats' => 2,
        'included_collaborator_seats' => 5,
    ]);
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $suspendedUser = User::factory()->create();

    $organization->memberships()->create([
        'user_id' => $administrator->id,
        'role' => TeamRole::Admin,
        'status' => MembershipStatus::Active,
        'created_by' => $owner->id,
    ]);
    $organization->memberships()->create([
        'user_id' => $collaborator->id,
        'role' => TeamRole::Member,
        'status' => MembershipStatus::Active,
        'created_by' => $owner->id,
    ]);
    $organization->memberships()->create([
        'user_id' => $suspendedUser->id,
        'role' => TeamRole::Member,
        'status' => MembershipStatus::Suspended,
        'created_by' => $owner->id,
    ]);

    $publishedCourse = Course::factory()->for($organization)->published()->create();
    Course::factory()->for($organization)->create();
    $courseModule = CourseModule::factory()->for($publishedCourse)->create();
    Lesson::factory()->count(2)->for($courseModule)->create();
    Lesson::factory()->for($courseModule)->create([
        'resource_size' => 50 * 1024 * 1024,
        'resource_path' => 'course-resources/large-file.pdf',
    ]);

    $this->actingAs($owner)
        ->get(route('platform.dashboard'))
        ->assertOk()
        ->assertSee('Empresa con seguimiento')
        ->assertSee('2 activos')
        ->assertSee('1 suspendidos')
        ->assertSee('2 cursos · 3 lecciones')
        ->assertSee('promedio 1,5 lecciones/curso')
        ->assertSee('50 MB en recursos');
});

it('creates an isolated organization from the global dashboard', function () {
    $owner = User::factory()->platformOwner()->create();

    Livewire::actingAs($owner)
        ->test('pages::platform.dashboard')
        ->set('organizationName', 'Nueva empresa global')
        ->set('organizationAdministratorSeats', '3')
        ->set('organizationCollaboratorSeats', '25')
        ->call('createOrganization')
        ->assertHasNoErrors();

    $organization = Team::query()->where('name', 'Nueva empresa global')->firstOrFail();

    expect($organization->is_personal)->toBeFalse()
        ->and($organization->included_administrator_seats)->toBe(3)
        ->and($organization->included_collaborator_seats)->toBe(25)
        ->and($owner->fresh()->belongsToTeam($organization))->toBeFalse();
});

it('creates an organization administrator from the global dashboard', function () {
    Notification::fake();

    $owner = User::factory()->platformOwner()->create();
    $organization = Team::factory()->create();

    Livewire::actingAs($owner)
        ->test('pages::platform.dashboard')
        ->set('administratorOrganizationId', $organization->id)
        ->set('administratorName', 'Administradora Global')
        ->set('administratorEmail', 'admin-global@example.com')
        ->set('administratorPassword', 'Password-local-2026')
        ->set('administratorPasswordConfirmation', 'Password-local-2026')
        ->call('createAdministrator')
        ->assertHasNoErrors();

    $administrator = User::query()->where('email', 'admin-global@example.com')->firstOrFail();

    expect($administrator->teamRole($organization))->toBe(TeamRole::Admin)
        ->and(Hash::check('Password-local-2026', $administrator->password))->toBeTrue()
        ->and($administrator->platform_role)->toBeNull()
        ->and($owner->fresh()->belongsToTeam($organization))->toBeFalse();

    Notification::assertNothingSent();
});

it('prevents a manipulated regular user from using global dashboard actions', function () {
    $regularUser = User::factory()->create(['platform_role' => null]);

    expect($regularUser->platform_role)->not->toBe(PlatformRole::Owner);

    Livewire::actingAs($regularUser)
        ->test('pages::platform.dashboard')
        ->assertForbidden();
});
