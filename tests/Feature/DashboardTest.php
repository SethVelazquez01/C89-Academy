<?php

use App\Enums\EnrollmentStatus;
use App\Enums\TeamRole;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Team;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->get(route('dashboard', ['current_team' => $team]))
        ->assertRedirect(route('login'));
});

test('authenticated users can visit an empty learning dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team]))
        ->assertOk()
        ->assertSee('Mi aprendizaje')
        ->assertSee($team->name)
        ->assertSee('No hay cursos publicados')
        ->assertSee('0%');
});

test('the dashboard only displays published courses from the current organization', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $publishedCourse = Course::factory()->for($team)->published()->create([
        'title' => 'Curso publicado visible',
    ]);
    $draftCourse = Course::factory()->for($team)->create([
        'title' => 'Curso borrador oculto',
    ]);
    $otherTeamCourse = Course::factory()->for(Team::factory())->published()->create([
        'title' => 'Curso publicado de otra empresa',
    ]);
    $deletedCourse = Course::factory()->for($team)->published()->create([
        'title' => 'Curso eliminado oculto',
    ]);
    $deletedCourse->delete();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team]))
        ->assertOk()
        ->assertSee($publishedCourse->title)
        ->assertDontSee($draftCourse->title)
        ->assertDontSee($otherTeamCourse->title)
        ->assertDontSee($deletedCourse->title)
        ->assertSee('Cursos disponibles')
        ->assertSee('Administrar cursos')
        ->assertDontSee('Inscribirme');
});

test('the dashboard displays the current enrollment status and learning totals', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $user->switchTeam($team);

    $activeCourse = Course::factory()->for($team)->published()->create([
        'title' => 'Curso activo',
    ]);
    $completedCourse = Course::factory()->for($team)->published()->create([
        'title' => 'Curso terminado',
    ]);

    CourseEnrollment::factory()
        ->for($activeCourse)
        ->for($user)
        ->create();
    CourseEnrollment::factory()
        ->for($completedCourse)
        ->for($user)
        ->completed()
        ->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team]))
        ->assertOk()
        ->assertSee('En curso')
        ->assertSee(EnrollmentStatus::Completed->label())
        ->assertSee('Continuar curso')
        ->assertSee('Revisar curso completado');
});
