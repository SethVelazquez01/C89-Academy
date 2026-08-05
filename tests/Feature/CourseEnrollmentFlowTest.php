<?php

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\C89OrganizationSeeder;

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
});

test('a collaborator can enroll in a published course', function () {
    $this->actingAs($this->collaborator)
        ->post(route('courses.enroll', [
            'current_team' => $this->team,
            'course' => $this->course,
        ]))
        ->assertRedirect(route('dashboard', ['current_team' => $this->team]))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('course_enrollments', [
        'course_id' => $this->course->id,
        'user_id' => $this->collaborator->id,
        'assigned_by' => null,
        'status' => EnrollmentStatus::Active->value,
    ]);
});

test('an administrator cannot enroll as a learner', function () {
    $this->actingAs($this->administrator)
        ->post(route('courses.enroll', [
            'current_team' => $this->team,
            'course' => $this->course,
        ]))
        ->assertForbidden();

    $this->assertDatabaseMissing('course_enrollments', [
        'course_id' => $this->course->id,
        'user_id' => $this->administrator->id,
    ]);
});

test('enrolling twice does not create duplicate records', function () {
    $route = route('courses.enroll', [
        'current_team' => $this->team,
        'course' => $this->course,
    ]);

    $this->actingAs($this->collaborator)->post($route);
    $this->actingAs($this->collaborator)->post($route);

    expect(CourseEnrollment::query()
        ->whereBelongsTo($this->course)
        ->whereBelongsTo($this->collaborator)
        ->count())->toBe(1);
});

test('a collaborator cannot enroll in a draft course', function () {
    $draftCourse = Course::factory()->for($this->team)->create([
        'status' => CourseStatus::Draft,
    ]);

    $this->actingAs($this->collaborator)
        ->post(route('courses.enroll', [
            'current_team' => $this->team,
            'course' => $draftCourse,
        ]))
        ->assertForbidden();

    $this->assertDatabaseMissing('course_enrollments', [
        'course_id' => $draftCourse->id,
        'user_id' => $this->collaborator->id,
    ]);
});

test('a course from another organization cannot be enrolled through the current organization', function () {
    $otherCourse = Course::factory()->for(Team::factory())->published()->create();

    $this->actingAs($this->collaborator)
        ->post(route('courses.enroll', [
            'current_team' => $this->team,
            'course' => $otherCourse,
        ]))
        ->assertNotFound();
});

test('a cancelled enrollment is reactivated', function () {
    $enrollment = CourseEnrollment::factory()
        ->for($this->course)
        ->for($this->collaborator)
        ->cancelled()
        ->create();

    $this->actingAs($this->collaborator)
        ->post(route('courses.enroll', [
            'current_team' => $this->team,
            'course' => $this->course,
        ]))
        ->assertRedirect();

    expect($enrollment->refresh()->status)->toBe(EnrollmentStatus::Active)
        ->and($enrollment->completed_at)->toBeNull();
});

test('a completed enrollment is not restarted', function () {
    $enrollment = CourseEnrollment::factory()
        ->for($this->course)
        ->for($this->collaborator)
        ->completed()
        ->create();

    $this->actingAs($this->collaborator)
        ->post(route('courses.enroll', [
            'current_team' => $this->team,
            'course' => $this->course,
        ]))
        ->assertRedirect();

    expect($enrollment->refresh()->status)->toBe(EnrollmentStatus::Completed)
        ->and($enrollment->completed_at)->not->toBeNull();
});
