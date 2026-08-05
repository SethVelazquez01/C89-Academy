<?php

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\C89OrganizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed(C89OrganizationSeeder::class);

    $this->team = Team::query()->where('slug', 'c89')->firstOrFail();
    $this->administrator = User::query()->where('email', 'admin@c89.com.mx')->firstOrFail();
    $this->collaborator = User::query()->where('email', 'colaborador@c89.com.mx')->firstOrFail();
    $this->course = Course::factory()->for($this->team)->published()->create();
});

test('an enrollment connects a user with a course', function () {
    $enrollment = CourseEnrollment::factory()
        ->for($this->course)
        ->for($this->collaborator)
        ->create();

    expect($enrollment->course->is($this->course))->toBeTrue()
        ->and($enrollment->user->is($this->collaborator))->toBeTrue()
        ->and($enrollment->status)->toBe(EnrollmentStatus::Active)
        ->and($enrollment->enrolled_at)->not->toBeNull()
        ->and($this->course->enrollments->first()?->is($enrollment))->toBeTrue()
        ->and($this->collaborator->courseEnrollments->first()?->is($enrollment))->toBeTrue();
});

test('a user cannot have duplicate enrollments for the same course', function () {
    CourseEnrollment::factory()
        ->for($this->course)
        ->for($this->collaborator)
        ->create();

    expect(fn () => CourseEnrollment::factory()
        ->for($this->course)
        ->for($this->collaborator)
        ->create())->toThrow(QueryException::class);
});

test('a member can only enroll in a published course from the current organization', function () {
    $draftCourse = Course::factory()->for($this->team)->create([
        'status' => CourseStatus::Draft,
    ]);
    $otherCourse = Course::factory()->for(Team::factory())->published()->create();

    expect(Gate::forUser($this->collaborator)->allows('create', [CourseEnrollment::class, $this->course]))->toBeTrue()
        ->and(Gate::forUser($this->collaborator)->allows('create', [CourseEnrollment::class, $draftCourse]))->toBeFalse()
        ->and(Gate::forUser($this->collaborator)->allows('create', [CourseEnrollment::class, $otherCourse]))->toBeFalse();
});

test('a completed enrollment records its completion date', function () {
    $enrollment = CourseEnrollment::factory()
        ->for($this->course)
        ->for($this->collaborator)
        ->completed()
        ->create();

    expect($enrollment->status)->toBe(EnrollmentStatus::Completed)
        ->and($enrollment->completed_at)->not->toBeNull();
});
