<?php

use App\Enums\CourseAccessMode;
use App\Enums\EnrollmentStatus;
use App\Enums\TeamRole;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

function courseAccessUsers(): array
{
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);
    $administrator->switchTeam($team);
    $collaborator->switchTeam($team);

    return [$administrator, $collaborator, $team];
}

test('collaborators only see open courses or assigned courses with permission', function () {
    [, $collaborator, $team] = courseAccessUsers();
    $open = Course::factory()->for($team)->published()->create([
        'title' => 'Curso libre',
        'access_mode' => CourseAccessMode::Open,
    ]);
    $assigned = Course::factory()->for($team)->published()->create([
        'title' => 'Curso con permiso',
        'access_mode' => CourseAccessMode::Assigned,
    ]);
    $blocked = Course::factory()->for($team)->published()->create([
        'title' => 'Curso bloqueado',
        'access_mode' => CourseAccessMode::Blocked,
    ]);

    $this->actingAs($collaborator)
        ->get(route('dashboard', ['current_team' => $team]))
        ->assertOk()
        ->assertSee($open->title)
        ->assertDontSee($assigned->title)
        ->assertDontSee($blocked->title);

    CourseEnrollment::factory()->for($assigned)->for($collaborator)->create();

    $this->actingAs($collaborator)
        ->get(route('dashboard', ['current_team' => $team]))
        ->assertOk()
        ->assertSee($open->title)
        ->assertSee($assigned->title)
        ->assertDontSee($blocked->title);
});

test('blocked courses remain inaccessible even with an old enrollment', function () {
    [, $collaborator, $team] = courseAccessUsers();
    $blocked = Course::factory()->for($team)->published()->create([
        'access_mode' => CourseAccessMode::Blocked,
    ]);
    CourseEnrollment::factory()->for($blocked)->for($collaborator)->create([
        'status' => EnrollmentStatus::Active,
    ]);

    expect(Gate::forUser($collaborator)->allows('view', $blocked))->toBeFalse()
        ->and(Gate::forUser($collaborator)->allows('create', [CourseEnrollment::class, $blocked]))->toBeFalse();

    $this->actingAs($collaborator)
        ->get(route('courses.show', ['current_team' => $team, 'course' => $blocked]))
        ->assertForbidden();
});

test('administrators can save the course access mode', function () {
    [$administrator, , $team] = courseAccessUsers();

    Livewire::actingAs($administrator)
        ->test('pages::admin.courses.create')
        ->set('title', 'Curso autorizado')
        ->set('accessMode', CourseAccessMode::Assigned->value)
        ->call('save')
        ->assertHasNoErrors();

    expect(Course::query()->sole()->access_mode)->toBe(CourseAccessMode::Assigned);
});
