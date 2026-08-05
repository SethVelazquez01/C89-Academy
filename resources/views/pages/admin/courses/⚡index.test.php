<?php

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\C89OrganizationSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(C89OrganizationSeeder::class);

    $this->team = Team::query()->where('slug', 'c89')->firstOrFail();
    $this->administrator = User::query()->where('email', 'admin@c89.com.mx')->firstOrFail();
    $this->collaborator = User::query()->where('email', 'colaborador@c89.com.mx')->firstOrFail();
});

it('allows an administrator to visit the course management page', function () {
    $this->actingAs($this->administrator)
        ->get(route('admin.courses.index', ['current_team' => $this->team]))
        ->assertOk()
        ->assertSee('Administrar cursos')
        ->assertSee('Todavía no hay cursos');
});

it('prevents a collaborator from visiting the course management page', function () {
    $this->actingAs($this->collaborator)
        ->get(route('admin.courses.index', ['current_team' => $this->team]))
        ->assertForbidden();
});

it('only lists courses from the current organization', function () {
    $currentCourse = Course::factory()->for($this->team)->create([
        'title' => 'Curso visible de C89',
    ]);
    $otherCourse = Course::factory()->for(Team::factory())->create([
        'title' => 'Curso privado de otra empresa',
    ]);

    $this->actingAs($this->administrator)
        ->get(route('admin.courses.index', ['current_team' => $this->team]))
        ->assertOk()
        ->assertSee($currentCourse->title)
        ->assertDontSee($otherCourse->title);
});

it('shows deleted courses in the trash instead of the active catalog', function () {
    $course = Course::factory()->for($this->team)->create([
        'title' => 'Curso en papelera',
    ]);
    $course->delete();

    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.index')
        ->assertDontSee('Curso en papelera')
        ->call('showTrashedCourses')
        ->assertSee('Curso en papelera');
});

it('restores a deleted course as a draft', function () {
    $course = Course::factory()->for($this->team)->published()->create();
    $course->delete();

    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.index')
        ->call('showTrashedCourses')
        ->call('restore', $course->id)
        ->assertHasNoErrors();

    $course->refresh();

    expect($course->trashed())->toBeFalse()
        ->and($course->status)->toBe(CourseStatus::Draft)
        ->and($course->published_at)->toBeNull();
});

it('cannot restore a deleted course from another organization', function () {
    $otherCourse = Course::factory()->for(Team::factory())->create();
    $otherCourse->delete();

    expect(fn () => Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.index')
        ->call('showTrashedCourses')
        ->call('restore', $otherCourse->id))
        ->toThrow(ModelNotFoundException::class);

    $this->assertSoftDeleted('courses', ['id' => $otherCourse->id]);
});
