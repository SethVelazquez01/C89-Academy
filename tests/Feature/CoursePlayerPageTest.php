<?php

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\LessonProgress;
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
    $this->course = Course::factory()->for($this->team)->published()->create([
        'title' => 'Seguridad operativa',
    ]);
    $this->courseModule = CourseModule::factory()
        ->for($this->course)
        ->published()
        ->create([
            'title' => 'Introducción',
            'position' => 1,
        ]);
    $this->firstLesson = Lesson::factory()
        ->for($this->courseModule)
        ->published()
        ->create([
            'title' => 'Bienvenida',
            'content' => 'Contenido visible de bienvenida.',
            'position' => 1,
        ]);
    $this->secondLesson = Lesson::factory()
        ->for($this->courseModule)
        ->video()
        ->published()
        ->create([
            'title' => 'Video de seguridad',
            'external_url' => 'https://example.com/video-seguridad',
            'position' => 2,
        ]);
    $this->enrollment = CourseEnrollment::factory()
        ->for($this->course)
        ->for($this->collaborator)
        ->create();
});

it('allows an enrolled collaborator to open the course player', function () {
    $this->actingAs($this->collaborator)
        ->get(route('courses.show', [
            'current_team' => $this->team,
            'course' => $this->course,
        ]))
        ->assertOk()
        ->assertSee('Seguridad operativa')
        ->assertSee('Introducción')
        ->assertSee('Bienvenida')
        ->assertSee('Contenido visible de bienvenida.')
        ->assertSee('Video de seguridad')
        ->assertSee('0%');
});

it('hides draft modules and lessons from the course player', function () {
    $draftLesson = Lesson::factory()
        ->for($this->courseModule)
        ->create([
            'title' => 'Lección todavía oculta',
            'is_published' => false,
        ]);
    $draftModule = CourseModule::factory()
        ->for($this->course)
        ->create([
            'title' => 'Módulo todavía oculto',
            'is_published' => false,
        ]);
    Lesson::factory()->for($draftModule)->published()->create([
        'title' => 'Lección de módulo oculto',
    ]);

    $this->actingAs($this->collaborator)
        ->get(route('courses.show', [
            'current_team' => $this->team,
            'course' => $this->course,
        ]))
        ->assertOk()
        ->assertDontSee($draftLesson->title)
        ->assertDontSee($draftModule->title)
        ->assertDontSee('Lección de módulo oculto');
});

it('prevents access without an enrollment', function () {
    $this->enrollment->delete();

    $this->actingAs($this->collaborator)
        ->get(route('courses.show', [
            'current_team' => $this->team,
            'course' => $this->course,
        ]))
        ->assertForbidden();
});

it('prevents an administrator from using the learner player', function () {
    $this->actingAs($this->administrator)
        ->get(route('courses.show', [
            'current_team' => $this->team,
            'course' => $this->course,
        ]))
        ->assertForbidden();
});

it('records the first lesson as started when the player opens', function () {
    Livewire::actingAs($this->collaborator)
        ->test('pages::courses.show', ['course' => $this->course->slug])
        ->assertSet('selectedLessonId', $this->firstLesson->id);

    $this->assertDatabaseHas('lesson_progress', [
        'course_enrollment_id' => $this->enrollment->id,
        'lesson_id' => $this->firstLesson->id,
        'completed_at' => null,
    ]);
});

it('lets a collaborator select another published lesson', function () {
    Livewire::actingAs($this->collaborator)
        ->test('pages::courses.show', ['course' => $this->course->slug])
        ->call('selectLesson', $this->secondLesson->id)
        ->assertSet('selectedLessonId', $this->secondLesson->id)
        ->assertSee('https://example.com/video-seguridad');

    $this->assertDatabaseHas('lesson_progress', [
        'course_enrollment_id' => $this->enrollment->id,
        'lesson_id' => $this->secondLesson->id,
        'completed_at' => null,
    ]);
});

it('advances through lessons and displays the real percentage', function () {
    $component = Livewire::actingAs($this->collaborator)
        ->test('pages::courses.show', ['course' => $this->course->slug])
        ->call('markSelectedLessonComplete')
        ->assertSet('selectedLessonId', $this->secondLesson->id)
        ->assertSee('50%');

    expect($this->enrollment->refresh()->status)->toBe(EnrollmentStatus::Active);

    $component
        ->call('markSelectedLessonComplete')
        ->assertSee('100%')
        ->assertSee('Lección completada');

    expect($this->enrollment->refresh()->status)->toBe(EnrollmentStatus::Completed)
        ->and($this->enrollment->completed_at)->not->toBeNull()
        ->and(LessonProgress::query()
            ->where('course_enrollment_id', $this->enrollment->id)
            ->whereNotNull('completed_at')
            ->count())->toBe(2);
});

it('does not resolve a course from another organization', function () {
    $otherCourse = Course::factory()->for(Team::factory())->published()->create();

    $this->actingAs($this->collaborator)
        ->get(route('courses.show', [
            'current_team' => $this->team,
            'course' => $otherCourse,
        ]))
        ->assertNotFound();
});
