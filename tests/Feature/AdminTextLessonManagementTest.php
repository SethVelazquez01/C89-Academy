<?php

use App\Enums\LessonType;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
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
    $this->collaborator = User::query()
        ->where('email', 'colaborador@c89.com.mx')
        ->firstOrFail();
    $this->course = Course::factory()->for($this->team)->create();
    $this->courseModule = CourseModule::factory()
        ->for($this->course)
        ->create(['title' => 'Introducción']);
});

it('allows an administrator to visit the module lesson manager', function () {
    $this->actingAs($this->administrator)
        ->get(route('admin.courses.modules.lessons', [
            'current_team' => $this->team,
            'course' => $this->course,
            'courseModule' => $this->courseModule,
        ]))
        ->assertOk()
        ->assertSee('Introducción')
        ->assertSee('Nueva lección');
});

it('prevents a collaborator from visiting the module lesson manager', function () {
    $this->actingAs($this->collaborator)
        ->get(route('admin.courses.modules.lessons', [
            'current_team' => $this->team,
            'course' => $this->course,
            'courseModule' => $this->courseModule,
        ]))
        ->assertForbidden();
});

it('allows an administrator to create a draft text lesson', function () {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.modules.lessons', [
            'course' => $this->course->slug,
            'courseModule' => $this->courseModule->id,
        ])
        ->set('lessonTitle', 'Bienvenida al curso')
        ->set('lessonContent', 'Este es el contenido inicial de la capacitación.')
        ->set('lessonEstimatedDurationMinutes', 10)
        ->call('createLesson')
        ->assertHasNoErrors()
        ->assertSee('Bienvenida al curso');

    $lesson = Lesson::query()->where('title', 'Bienvenida al curso')->firstOrFail();

    expect($lesson->course_module_id)->toBe($this->courseModule->id)
        ->and($lesson->type)->toBe(LessonType::Text)
        ->and($lesson->slug)->toBe('bienvenida-al-curso')
        ->and($lesson->position)->toBe(1)
        ->and($lesson->is_published)->toBeFalse();
});

it('allows an administrator to create a video lesson from a secure URL', function () {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.modules.lessons', [
            'course' => $this->course->slug,
            'courseModule' => $this->courseModule->id,
        ])
        ->set('lessonTitle', 'Video de seguridad')
        ->set('lessonType', LessonType::Video->value)
        ->set('lessonExternalUrl', 'https://video.example.com/seguridad')
        ->set('lessonEstimatedDurationMinutes', 12)
        ->call('createLesson')
        ->assertHasNoErrors()
        ->assertSee('Video de seguridad');

    $this->assertDatabaseHas('lessons', [
        'course_module_id' => $this->courseModule->id,
        'title' => 'Video de seguridad',
        'type' => LessonType::Video->value,
        'content' => null,
        'external_url' => 'https://video.example.com/seguridad',
    ]);
});

it('allows an administrator to create an external link lesson', function () {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.modules.lessons', [
            'course' => $this->course->slug,
            'courseModule' => $this->courseModule->id,
        ])
        ->set('lessonTitle', 'Normativa de consulta')
        ->set('lessonType', LessonType::Link->value)
        ->set('lessonExternalUrl', 'https://example.com/normativa')
        ->call('createLesson')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('lessons', [
        'course_module_id' => $this->courseModule->id,
        'title' => 'Normativa de consulta',
        'type' => LessonType::Link->value,
        'external_url' => 'https://example.com/normativa',
    ]);
});

it('allows an administrator to edit a lesson without changing its slug', function () {
    $lesson = Lesson::factory()->for($this->courseModule)->create([
        'title' => 'Título anterior',
        'content' => 'Contenido anterior.',
    ]);
    $originalSlug = $lesson->slug;

    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.modules.lessons', [
            'course' => $this->course->slug,
            'courseModule' => $this->courseModule->id,
        ])
        ->call('startEditingLesson', $lesson->id)
        ->set('editingLessonTitle', 'Título actualizado')
        ->set('editingLessonContent', 'Contenido actualizado.')
        ->set('editingLessonEstimatedDurationMinutes', 15)
        ->call('updateLesson')
        ->assertHasNoErrors()
        ->assertSee('Título actualizado');

    $lesson->refresh();

    expect($lesson->title)->toBe('Título actualizado')
        ->and($lesson->content)->toBe('Contenido actualizado.')
        ->and($lesson->estimated_duration_minutes)->toBe(15)
        ->and($lesson->slug)->toBe($originalSlug);
});

it('allows an administrator to publish and unpublish a lesson', function () {
    $lesson = Lesson::factory()->for($this->courseModule)->create([
        'is_published' => false,
    ]);

    $component = Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.modules.lessons', [
            'course' => $this->course->slug,
            'courseModule' => $this->courseModule->id,
        ])
        ->call('toggleLessonPublication', $lesson->id)
        ->assertHasNoErrors();

    expect($lesson->refresh()->is_published)->toBeTrue();

    $component
        ->call('toggleLessonPublication', $lesson->id)
        ->assertHasNoErrors();

    expect($lesson->refresh()->is_published)->toBeFalse();
});

it('allows an administrator to move a lesson to the trash', function () {
    $lesson = Lesson::factory()->for($this->courseModule)->create();

    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.modules.lessons', [
            'course' => $this->course->slug,
            'courseModule' => $this->courseModule->id,
        ])
        ->call('deleteLesson', $lesson->id)
        ->assertHasNoErrors();

    $this->assertSoftDeleted('lessons', ['id' => $lesson->id]);
});

it('validates a new text lesson', function () {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.modules.lessons', [
            'course' => $this->course->slug,
            'courseModule' => $this->courseModule->id,
        ])
        ->set('lessonTitle', '')
        ->set('lessonContent', '')
        ->set('lessonEstimatedDurationMinutes', 0)
        ->call('createLesson')
        ->assertHasErrors([
            'lessonTitle' => 'required',
            'lessonContent' => 'required_if',
            'lessonEstimatedDurationMinutes' => 'min',
        ]);
});

it('requires a valid web URL for video and link lessons', function () {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.modules.lessons', [
            'course' => $this->course->slug,
            'courseModule' => $this->courseModule->id,
        ])
        ->set('lessonTitle', 'Video inválido')
        ->set('lessonType', LessonType::Video->value)
        ->set('lessonExternalUrl', 'esto-no-es-una-url')
        ->call('createLesson')
        ->assertHasErrors(['lessonExternalUrl' => 'url']);
});

it('does not resolve a module from another course', function () {
    $otherCourse = Course::factory()->for($this->team)->create();
    $otherModule = CourseModule::factory()->for($otherCourse)->create();

    $this->actingAs($this->administrator)
        ->get(route('admin.courses.modules.lessons', [
            'current_team' => $this->team,
            'course' => $this->course,
            'courseModule' => $otherModule,
        ]))
        ->assertNotFound();
});
