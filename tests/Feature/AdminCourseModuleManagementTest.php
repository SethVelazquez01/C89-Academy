<?php

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\C89OrganizationSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(C89OrganizationSeeder::class);

    $this->team = Team::query()->where('slug', 'c89')->firstOrFail();
    $this->administrator = User::query()
        ->where('email', 'admin@c89.com.mx')
        ->firstOrFail();
    $this->course = Course::factory()->for($this->team)->create([
        'title' => 'Curso con contenido',
    ]);
});

it('allows an administrator to create a draft module', function () {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.edit', ['course' => $this->course->slug])
        ->set('moduleTitle', 'Introducción')
        ->set('moduleDescription', 'Conceptos iniciales del curso.')
        ->call('createModule')
        ->assertHasNoErrors()
        ->assertSee('Introducción');

    $this->assertDatabaseHas('course_modules', [
        'course_id' => $this->course->id,
        'title' => 'Introducción',
        'description' => 'Conceptos iniciales del curso.',
        'position' => 1,
        'is_published' => false,
    ]);
});

it('places new modules after existing modules', function () {
    CourseModule::factory()->for($this->course)->create(['position' => 1]);

    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.edit', ['course' => $this->course->slug])
        ->set('moduleTitle', 'Segundo módulo')
        ->call('createModule')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('course_modules', [
        'course_id' => $this->course->id,
        'title' => 'Segundo módulo',
        'position' => 2,
    ]);
});

it('allows an administrator to edit a module', function () {
    $courseModule = CourseModule::factory()->for($this->course)->create([
        'title' => 'Nombre anterior',
    ]);

    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.edit', ['course' => $this->course->slug])
        ->call('startEditingModule', $courseModule->id)
        ->set('editingModuleTitle', 'Nombre actualizado')
        ->set('editingModuleDescription', 'Descripción actualizada.')
        ->call('updateModule')
        ->assertHasNoErrors()
        ->assertSee('Nombre actualizado');

    expect($courseModule->refresh()->title)->toBe('Nombre actualizado')
        ->and($courseModule->description)->toBe('Descripción actualizada.');
});

it('allows an administrator to publish and unpublish a module', function () {
    $courseModule = CourseModule::factory()->for($this->course)->create([
        'is_published' => false,
    ]);

    $component = Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.edit', ['course' => $this->course->slug])
        ->call('toggleModulePublication', $courseModule->id)
        ->assertHasNoErrors();

    expect($courseModule->refresh()->is_published)->toBeTrue();

    $component
        ->call('toggleModulePublication', $courseModule->id)
        ->assertHasNoErrors();

    expect($courseModule->refresh()->is_published)->toBeFalse();
});

it('allows an administrator to move a module to the trash', function () {
    $courseModule = CourseModule::factory()->for($this->course)->create();

    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.edit', ['course' => $this->course->slug])
        ->call('deleteModule', $courseModule->id)
        ->assertHasNoErrors();

    $this->assertSoftDeleted('course_modules', [
        'id' => $courseModule->id,
    ]);
});

it('validates a new module', function () {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.edit', ['course' => $this->course->slug])
        ->set('moduleTitle', '')
        ->call('createModule')
        ->assertHasErrors(['moduleTitle' => 'required']);
});
