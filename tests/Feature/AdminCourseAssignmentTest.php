<?php

use App\Actions\Courses\AssignCourseToCollaborator;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\MembershipStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\C89OrganizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(C89OrganizationSeeder::class);

    $this->team = Team::query()->where('slug', 'c89')->firstOrFail();
    $this->administrator = User::query()->where('email', 'admin@c89.com.mx')->firstOrFail();
    $this->collaborator = User::query()->where('email', 'colaborador@c89.com.mx')->firstOrFail();
    $this->course = Course::factory()->for($this->team)->published()->create([
        'title' => 'Prevención de riesgos',
    ]);
});

it('allows an administrator to assign a required course with a due date', function () {
    $dueDate = now()->addDays(7)->toDateString();

    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.assignments', ['course' => $this->course->slug])
        ->set('collaboratorId', $this->collaborator->id)
        ->set('isRequired', true)
        ->set('dueDate', $dueDate)
        ->call('assign')
        ->assertHasNoErrors()
        ->assertSee('Colaborador C89');

    $enrollment = CourseEnrollment::query()->firstOrFail();

    expect($enrollment->course_id)->toBe($this->course->id)
        ->and($enrollment->user_id)->toBe($this->collaborator->id)
        ->and($enrollment->assigned_by)->toBe($this->administrator->id)
        ->and($enrollment->is_required)->toBeTrue()
        ->and($enrollment->due_at?->toDateString())->toBe($dueDate)
        ->and($enrollment->status)->toBe(EnrollmentStatus::Active);
});

it('allows an administrator to assign an optional course without a due date', function () {
    $enrollment = app(AssignCourseToCollaborator::class)->handle(
        $this->administrator,
        $this->collaborator,
        $this->course,
        false,
    );

    expect($enrollment->is_required)->toBeFalse()
        ->and($enrollment->due_at)->toBeNull()
        ->and($enrollment->assigned_by)->toBe($this->administrator->id);
});

it('updates an existing assignment without duplicating or resetting progress', function () {
    $enrollment = CourseEnrollment::factory()
        ->for($this->course)
        ->for($this->collaborator)
        ->create([
            'enrolled_at' => now()->subWeek(),
        ]);
    $originalEnrollmentDate = $enrollment->enrolled_at;

    $updatedEnrollment = app(AssignCourseToCollaborator::class)->handle(
        $this->administrator,
        $this->collaborator,
        $this->course,
        true,
        now()->addMonth(),
    );

    expect(CourseEnrollment::query()->count())->toBe(1)
        ->and($updatedEnrollment->id)->toBe($enrollment->id)
        ->and($updatedEnrollment->enrolled_at->equalTo($originalEnrollmentDate))->toBeTrue()
        ->and($updatedEnrollment->is_required)->toBeTrue();
});

it('reactivates a cancelled enrollment when the course is assigned again', function () {
    $enrollment = CourseEnrollment::factory()
        ->for($this->course)
        ->for($this->collaborator)
        ->cancelled()
        ->create();

    $reactivatedEnrollment = app(AssignCourseToCollaborator::class)->handle(
        $this->administrator,
        $this->collaborator,
        $this->course,
        true,
    );

    expect($reactivatedEnrollment->id)->toBe($enrollment->id)
        ->and($reactivatedEnrollment->status)->toBe(EnrollmentStatus::Active)
        ->and($reactivatedEnrollment->completed_at)->toBeNull();
});

it('does not restart a completed enrollment when its assignment details change', function () {
    $enrollment = CourseEnrollment::factory()
        ->for($this->course)
        ->for($this->collaborator)
        ->completed()
        ->create();
    $completedAt = $enrollment->completed_at;

    $updatedEnrollment = app(AssignCourseToCollaborator::class)->handle(
        $this->administrator,
        $this->collaborator,
        $this->course,
        true,
        now()->addMonth(),
    );

    expect($updatedEnrollment->status)->toBe(EnrollmentStatus::Completed)
        ->and($updatedEnrollment->completed_at?->equalTo($completedAt))->toBeTrue()
        ->and($updatedEnrollment->is_required)->toBeTrue();
});

it('rejects assignments to suspended collaborators', function () {
    Membership::query()
        ->where('team_id', $this->team->id)
        ->where('user_id', $this->collaborator->id)
        ->update(['status' => MembershipStatus::Suspended]);

    expect(fn () => app(AssignCourseToCollaborator::class)->handle(
        $this->administrator,
        $this->collaborator,
        $this->course,
        true,
    ))->toThrow(ValidationException::class);

    $this->assertDatabaseMissing('course_enrollments', [
        'course_id' => $this->course->id,
        'user_id' => $this->collaborator->id,
    ]);
});

it('rejects assignments to administrators and users from another organization', function () {
    expect(fn () => app(AssignCourseToCollaborator::class)->handle(
        $this->administrator,
        $this->administrator,
        $this->course,
        true,
    ))->toThrow(ValidationException::class);

    $otherUser = User::factory()->create();

    expect(fn () => app(AssignCourseToCollaborator::class)->handle(
        $this->administrator,
        $otherUser,
        $this->course,
        true,
    ))->toThrow(ValidationException::class);
});

it('rejects assignments for draft courses', function () {
    $this->course->update([
        'status' => CourseStatus::Draft,
        'published_at' => null,
    ]);

    expect(fn () => app(AssignCourseToCollaborator::class)->handle(
        $this->administrator,
        $this->collaborator,
        $this->course,
        true,
    ))->toThrow(AuthorizationException::class);
});

it('rejects past due dates', function () {
    expect(fn () => app(AssignCourseToCollaborator::class)->handle(
        $this->administrator,
        $this->collaborator,
        $this->course,
        true,
        now()->subDay(),
    ))->toThrow(ValidationException::class);
});

it('prevents collaborators from visiting assignment management', function () {
    $this->actingAs($this->collaborator)
        ->get(route('admin.courses.assignments', [
            'current_team' => $this->team,
            'course' => $this->course,
        ]))
        ->assertForbidden();
});

it('does not resolve courses from another organization', function () {
    $otherTeam = Team::factory()->create();
    $otherCourse = Course::factory()->for($otherTeam)->published()->create();

    $this->actingAs($this->administrator)
        ->get(route('admin.courses.assignments', [
            'current_team' => $this->team,
            'course' => $otherCourse,
        ]))
        ->assertNotFound();
});
