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
    $this->course = Course::factory()->for($this->team)->create([
        'title' => 'Curso original',
    ]);
});

it('allows an administrator to visit the edit course page', function () {
    $this->actingAs($this->administrator)
        ->get(route('admin.courses.edit', [
            'current_team' => $this->team,
            'course' => $this->course,
        ]))
        ->assertOk()
        ->assertSee('Editar curso')
        ->assertSee('Curso original');
});

it('prevents a collaborator from visiting the edit course page', function () {
    $this->actingAs($this->collaborator)
        ->get(route('admin.courses.edit', [
            'current_team' => $this->team,
            'course' => $this->course,
        ]))
        ->assertForbidden();
});

it('allows an administrator to update a course', function () {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.edit', ['course' => $this->course->slug])
        ->set('title', 'Curso actualizado')
        ->set('summary', 'Resumen actualizado para el catálogo.')
        ->set('description', 'Nueva descripción del curso.')
        ->set('estimatedDurationMinutes', 120)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.courses.index', ['current_team' => $this->team]));

    $this->course->refresh();

    expect($this->course->title)->toBe('Curso actualizado')
        ->and($this->course->summary)->toBe('Resumen actualizado para el catálogo.')
        ->and($this->course->description)->toBe('Nueva descripción del curso.')
        ->and($this->course->estimated_duration_minutes)->toBe(120)
        ->and($this->course->slug)->toBe('curso-original');
});

it('allows an administrator to publish a draft course', function () {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.edit', ['course' => $this->course->slug])
        ->call('publish')
        ->assertHasNoErrors()
        ->assertSee('Publicado');

    $this->course->refresh();

    expect($this->course->status)->toBe(CourseStatus::Published)
        ->and($this->course->published_at)->not->toBeNull();
});

it('allows an administrator to return a published course to draft', function () {
    $this->course->update([
        'status' => CourseStatus::Published,
        'published_at' => now(),
    ]);

    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.edit', ['course' => $this->course->slug])
        ->call('unpublish')
        ->assertHasNoErrors()
        ->assertSee('Borrador');

    $this->course->refresh();

    expect($this->course->status)->toBe(CourseStatus::Draft)
        ->and($this->course->published_at)->toBeNull();
});

it('allows an administrator to move a course to the trash', function () {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.edit', ['course' => $this->course->slug])
        ->call('moveToTrash')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.courses.index', ['current_team' => $this->team]));

    $this->assertSoftDeleted('courses', ['id' => $this->course->id]);
});

it('does not resolve a course from another organization', function () {
    $otherCourse = Course::factory()->for(Team::factory())->create();

    $this->actingAs($this->administrator)
        ->get(route('admin.courses.edit', [
            'current_team' => $this->team,
            'course' => $otherCourse,
        ]))
        ->assertNotFound();
});

it('validates course updates', function () {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.edit', ['course' => $this->course->slug])
        ->set('title', '')
        ->set('estimatedDurationMinutes', 0)
        ->call('save')
        ->assertHasErrors([
            'title' => 'required',
            'estimatedDurationMinutes' => 'min',
        ]);
});
