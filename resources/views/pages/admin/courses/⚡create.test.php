<?php

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\C89OrganizationSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(C89OrganizationSeeder::class);

    $this->team = Team::query()->where('slug', 'c89')->firstOrFail();
    $this->administrator = User::query()->where('email', 'admin@c89.com.mx')->firstOrFail();
    $this->collaborator = User::query()->where('email', 'colaborador@c89.com.mx')->firstOrFail();
});

it('allows an administrator to visit the create course page', function () {
    $this->actingAs($this->administrator)
        ->get(route('admin.courses.create', ['current_team' => $this->team]))
        ->assertOk()
        ->assertSee('Crear curso');
});

it('prevents a collaborator from visiting the create course page', function () {
    $this->actingAs($this->collaborator)
        ->get(route('admin.courses.create', ['current_team' => $this->team]))
        ->assertForbidden();
});

it('allows an administrator to create a draft course', function () {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.create')
        ->set('title', 'Inducción para nuevos colaboradores')
        ->set('summary', 'Conoce la empresa y sus procesos principales.')
        ->set('description', 'Curso inicial para las nuevas incorporaciones de C89.')
        ->set('estimatedDurationMinutes', 90)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.courses.index', ['current_team' => $this->team]));

    $course = Course::query()->sole();

    expect($course->team->is($this->team))->toBeTrue()
        ->and($course->creator?->is($this->administrator))->toBeTrue()
        ->and($course->status)->toBe(CourseStatus::Draft)
        ->and($course->title)->toBe('Inducción para nuevos colaboradores')
        ->and($course->slug)->toBe('induccion-para-nuevos-colaboradores');
});

it('validates the required course information', function () {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.create')
        ->set('title', '')
        ->set('estimatedDurationMinutes', 0)
        ->call('save')
        ->assertHasErrors([
            'title' => 'required',
            'estimatedDurationMinutes' => 'min',
        ]);
});
