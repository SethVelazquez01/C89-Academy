<?php

use App\Actions\Courses\MarkLessonComplete;
use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\C89OrganizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;

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
    $this->firstLesson = Lesson::factory()
        ->for($this->courseModule)
        ->published()
        ->create(['position' => 1]);
    $this->secondLesson = Lesson::factory()
        ->for($this->courseModule)
        ->published()
        ->create(['position' => 2]);
    $this->enrollment = CourseEnrollment::factory()
        ->for($this->course)
        ->for($this->collaborator)
        ->create();
});

it('records when a collaborator completes a published lesson', function () {
    $progress = app(MarkLessonComplete::class)->handle(
        $this->collaborator,
        $this->enrollment,
        $this->firstLesson,
    );

    expect($progress->course_enrollment_id)->toBe($this->enrollment->id)
        ->and($progress->lesson_id)->toBe($this->firstLesson->id)
        ->and($progress->started_at)->not->toBeNull()
        ->and($progress->completed_at)->not->toBeNull()
        ->and($progress->courseEnrollment->is($this->enrollment))->toBeTrue()
        ->and($progress->lesson->is($this->firstLesson))->toBeTrue()
        ->and($this->enrollment->refresh()->status)->toBe(EnrollmentStatus::Active);
});

it('does not duplicate progress when a lesson is completed twice', function () {
    $action = app(MarkLessonComplete::class);

    $action->handle($this->collaborator, $this->enrollment, $this->firstLesson);
    $action->handle($this->collaborator, $this->enrollment, $this->firstLesson);

    expect(LessonProgress::query()
        ->where('course_enrollment_id', $this->enrollment->id)
        ->where('lesson_id', $this->firstLesson->id)
        ->count())->toBe(1);
});

it('completes the enrollment after all published lessons are completed', function () {
    $action = app(MarkLessonComplete::class);

    $action->handle($this->collaborator, $this->enrollment, $this->firstLesson);
    $action->handle($this->collaborator, $this->enrollment, $this->secondLesson);

    $this->enrollment->refresh();

    expect($this->enrollment->status)->toBe(EnrollmentStatus::Completed)
        ->and($this->enrollment->completed_at)->not->toBeNull()
        ->and($this->enrollment->lessonProgress()->whereNotNull('completed_at')->count())->toBe(2);
});

it('ignores unpublished content when calculating course completion', function () {
    $draftModule = CourseModule::factory()
        ->for($this->course)
        ->create(['is_published' => false]);
    Lesson::factory()
        ->for($draftModule)
        ->published()
        ->create();
    Lesson::factory()
        ->for($this->courseModule)
        ->create(['is_published' => false]);

    $action = app(MarkLessonComplete::class);
    $action->handle($this->collaborator, $this->enrollment, $this->firstLesson);
    $action->handle($this->collaborator, $this->enrollment, $this->secondLesson);

    expect($this->enrollment->refresh()->status)->toBe(EnrollmentStatus::Completed);
});

it('prevents completion of an unpublished lesson', function () {
    $this->firstLesson->update(['is_published' => false]);

    expect(fn () => app(MarkLessonComplete::class)->handle(
        $this->collaborator,
        $this->enrollment,
        $this->firstLesson,
    ))->toThrow(AuthorizationException::class);

    expect(LessonProgress::query()->count())->toBe(0);
});

it('prevents a different user from completing a collaborators lesson', function () {
    expect(fn () => app(MarkLessonComplete::class)->handle(
        $this->administrator,
        $this->enrollment,
        $this->firstLesson,
    ))->toThrow(AuthorizationException::class);

    expect(LessonProgress::query()->count())->toBe(0);
});

it('keeps completed progress available for auditing', function () {
    $progress = LessonProgress::factory()
        ->for($this->enrollment)
        ->for($this->firstLesson)
        ->completed()
        ->create();

    expect($this->enrollment->lessonProgress->first()?->is($progress))->toBeTrue()
        ->and($this->firstLesson->progressRecords->first()?->is($progress))->toBeTrue();
});
