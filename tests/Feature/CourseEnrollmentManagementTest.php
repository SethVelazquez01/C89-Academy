<?php

use App\Actions\Courses\CancelCourseEnrollment;
use App\Enums\EnrollmentProgressStatus;
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
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(C89OrganizationSeeder::class);

    $this->team = Team::query()->where('slug', 'c89')->firstOrFail();
    $this->administrator = User::query()->where('email', 'admin@c89.com.mx')->firstOrFail();
    $this->collaborator = User::query()->where('email', 'colaborador@c89.com.mx')->firstOrFail();
    $this->course = Course::factory()->for($this->team)->published()->create([
        'title' => 'Seguridad industrial',
    ]);
    $this->courseModule = CourseModule::factory()
        ->for($this->course)
        ->published()
        ->create();
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
        ->create([
            'assigned_by' => $this->administrator->id,
            'is_required' => true,
        ]);
});

it('derives the professional progress states', function () {
    expect($this->enrollment->progressStatus(0, 2))->toBe(EnrollmentProgressStatus::Pending)
        ->and($this->enrollment->progressStatus(1, 2))->toBe(EnrollmentProgressStatus::InProgress)
        ->and($this->enrollment->progressPercentage(1, 2))->toBe(50);

    $this->enrollment->update(['due_at' => now()->subDay()]);

    expect($this->enrollment->refresh()->progressStatus(1, 2))->toBe(EnrollmentProgressStatus::Overdue);

    $this->enrollment->update([
        'status' => EnrollmentStatus::Completed,
        'completed_at' => now(),
    ]);

    expect($this->enrollment->refresh()->progressStatus(2, 2))->toBe(EnrollmentProgressStatus::Completed)
        ->and($this->enrollment->progressPercentage(2, 2))->toBe(100);

    $this->enrollment->update(['status' => EnrollmentStatus::Cancelled]);

    expect($this->enrollment->refresh()->progressStatus(1, 2))->toBe(EnrollmentProgressStatus::Cancelled);
});

it('cancels an active enrollment without deleting its progress', function () {
    $progress = LessonProgress::factory()
        ->for($this->enrollment)
        ->for($this->firstLesson)
        ->completed()
        ->create();

    $cancelledEnrollment = app(CancelCourseEnrollment::class)->handle(
        $this->administrator,
        $this->enrollment,
    );

    expect($cancelledEnrollment->status)->toBe(EnrollmentStatus::Cancelled)
        ->and($cancelledEnrollment->last_cancelled_by)->toBe($this->administrator->id)
        ->and($cancelledEnrollment->last_cancelled_at)->not->toBeNull()
        ->and($cancelledEnrollment->lessonProgress()->first()?->is($progress))->toBeTrue();
});

it('allows cancellation from the assignment management page', function () {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.assignments', ['course' => $this->course->slug])
        ->call('cancel', $this->enrollment->id)
        ->assertHasNoErrors()
        ->assertSee('Cancelado')
        ->assertSee('Reasignar');

    expect($this->enrollment->refresh()->status)->toBe(EnrollmentStatus::Cancelled);
});

it('reassigns a cancelled enrollment and retains its cancellation audit and progress', function () {
    $progress = LessonProgress::factory()
        ->for($this->enrollment)
        ->for($this->firstLesson)
        ->completed()
        ->create();
    app(CancelCourseEnrollment::class)->handle($this->administrator, $this->enrollment);
    $lastCancelledAt = $this->enrollment->refresh()->last_cancelled_at;

    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.assignments', ['course' => $this->course->slug])
        ->call('prepareReassignment', $this->enrollment->id)
        ->assertSet('collaboratorId', $this->collaborator->id)
        ->assertSet('isRequired', true)
        ->call('assign')
        ->assertHasNoErrors()
        ->assertSet('reassigningEnrollmentId', null);

    $this->enrollment->refresh();

    expect($this->enrollment->status)->toBe(EnrollmentStatus::Active)
        ->and($this->enrollment->last_cancelled_at?->equalTo($lastCancelledAt))->toBeTrue()
        ->and($this->enrollment->lessonProgress()->first()?->is($progress))->toBeTrue()
        ->and(CourseEnrollment::query()->count())->toBe(1);
});

it('prevents collaborators and administrators from other organizations from cancelling enrollments', function () {
    expect(fn () => app(CancelCourseEnrollment::class)->handle(
        $this->collaborator,
        $this->enrollment,
    ))->toThrow(AuthorizationException::class);

    $otherTeam = Team::factory()->create();
    $otherAdministrator = User::factory()->create(['current_team_id' => $otherTeam->id]);
    $otherTeam->members()->attach($otherAdministrator, [
        'role' => 'admin',
        'status' => 'active',
    ]);

    expect(fn () => app(CancelCourseEnrollment::class)->handle(
        $otherAdministrator,
        $this->enrollment,
    ))->toThrow(AuthorizationException::class);
});

it('does not cancel completed or already cancelled enrollments', function (EnrollmentStatus $status) {
    $this->enrollment->update([
        'status' => $status,
        'completed_at' => $status === EnrollmentStatus::Completed ? now() : null,
    ]);

    expect(fn () => app(CancelCourseEnrollment::class)->handle(
        $this->administrator,
        $this->enrollment,
    ))->toThrow(ValidationException::class);
})->with([
    'completed' => EnrollmentStatus::Completed,
    'cancelled' => EnrollmentStatus::Cancelled,
]);

it('shows assignment type deadline progress and overdue status to the collaborator', function () {
    $this->enrollment->update(['due_at' => now()->subDay()]);
    LessonProgress::factory()
        ->for($this->enrollment)
        ->for($this->firstLesson)
        ->completed()
        ->create();

    $this->actingAs($this->collaborator)
        ->get(route('dashboard', ['current_team' => $this->team]))
        ->assertOk()
        ->assertSee('Atrasado')
        ->assertSee('Obligatorio')
        ->assertSee('50%')
        ->assertSee($this->enrollment->due_at?->format('d/m/Y'));
});

it('ignores draft content when displaying progress', function () {
    $draftModule = CourseModule::factory()->for($this->course)->create([
        'is_published' => false,
    ]);
    Lesson::factory()->for($draftModule)->published()->create();
    Lesson::factory()->for($this->courseModule)->create([
        'is_published' => false,
    ]);
    LessonProgress::factory()
        ->for($this->enrollment)
        ->for($this->firstLesson)
        ->completed()
        ->create();

    $this->actingAs($this->collaborator)
        ->get(route('dashboard', ['current_team' => $this->team]))
        ->assertOk()
        ->assertSee('1 de 2 lecciones')
        ->assertSee('50%');
});
