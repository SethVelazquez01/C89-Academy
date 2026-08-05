<?php

use App\Enums\LessonType;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\C89OrganizationSeeder;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed(C89OrganizationSeeder::class);

    $this->team = Team::query()->where('slug', 'c89')->firstOrFail();
    $this->administrator = User::query()
        ->where('email', 'admin@c89.com.mx')
        ->firstOrFail();
    $this->collaborator = User::query()
        ->where('email', 'colaborador@c89.com.mx')
        ->firstOrFail();
    $this->course = Course::factory()->for($this->team)->published()->create();
    $this->courseModule = CourseModule::factory()
        ->for($this->course)
        ->published()
        ->create(['position' => 1]);
    $this->lesson = Lesson::factory()
        ->for($this->courseModule)
        ->published()
        ->create(['position' => 1]);
});

test('a course contains ordered modules and modules contain ordered lessons', function () {
    $secondModule = CourseModule::factory()
        ->for($this->course)
        ->create(['position' => 2]);
    $secondLesson = Lesson::factory()
        ->for($this->courseModule)
        ->create(['position' => 2]);

    expect($this->course->modules()->pluck('id')->all())->toBe([
        $this->courseModule->id,
        $secondModule->id,
    ])->and($this->courseModule->lessons()->pluck('id')->all())->toBe([
        $this->lesson->id,
        $secondLesson->id,
    ]);
});

test('lesson types are cast and lesson slugs are unique within a module', function () {
    $firstLesson = Lesson::factory()
        ->for($this->courseModule)
        ->create([
            'title' => 'Introducción al curso',
            'type' => LessonType::Video,
        ]);
    $secondLesson = Lesson::factory()
        ->for($this->courseModule)
        ->create([
            'title' => 'Introducción al curso',
            'type' => LessonType::Document,
        ]);

    expect($firstLesson->type)->toBe(LessonType::Video)
        ->and($firstLesson->slug)->toBe('introduccion-al-curso')
        ->and($secondLesson->slug)->toBe('introduccion-al-curso-2');
});

test('administrators can manage course content but collaborators cannot', function () {
    expect(Gate::forUser($this->administrator)
        ->allows('create', [CourseModule::class, $this->course]))->toBeTrue()
        ->and(Gate::forUser($this->administrator)
            ->allows('create', [Lesson::class, $this->courseModule]))->toBeTrue()
        ->and(Gate::forUser($this->collaborator)
            ->allows('create', [CourseModule::class, $this->course]))->toBeFalse()
        ->and(Gate::forUser($this->collaborator)
            ->allows('create', [Lesson::class, $this->courseModule]))->toBeFalse();
});

test('a collaborator must be enrolled to view published content', function () {
    expect(Gate::forUser($this->collaborator)->allows('view', $this->lesson))->toBeFalse();

    CourseEnrollment::factory()
        ->for($this->course)
        ->for($this->collaborator)
        ->create();

    expect(Gate::forUser($this->collaborator)->allows('view', $this->courseModule))->toBeTrue()
        ->and(Gate::forUser($this->collaborator)->allows('view', $this->lesson))->toBeTrue();
});

test('unpublished content stays hidden from collaborators', function () {
    CourseEnrollment::factory()
        ->for($this->course)
        ->for($this->collaborator)
        ->create();

    $this->lesson->update(['is_published' => false]);

    expect(Gate::forUser($this->collaborator)->allows('view', $this->lesson))->toBeFalse()
        ->and(Gate::forUser($this->administrator)->allows('view', $this->lesson))->toBeTrue();
});

test('course content cannot be managed from another organization', function () {
    $otherCourse = Course::factory()->for(Team::factory())->published()->create();
    $otherModule = CourseModule::factory()->for($otherCourse)->published()->create();

    expect(Gate::forUser($this->administrator)
        ->allows('update', $otherModule))->toBeFalse();
});
