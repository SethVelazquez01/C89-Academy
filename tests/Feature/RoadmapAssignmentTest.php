<?php

use App\Actions\Roadmaps\AssignRoadmapToCollaborator;
use App\Actions\Roadmaps\CancelRoadmapAssignment;
use App\Enums\CourseAccessMode;
use App\Enums\EnrollmentStatus;
use App\Enums\RoadmapAssignmentStatus;
use App\Enums\TeamRole;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Exam;
use App\Models\Roadmap;
use App\Models\Team;
use App\Models\User;

function roadmapAssignmentUsers(): array
{
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $otherCollaborator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);
    $team->members()->attach($otherCollaborator, ['role' => TeamRole::Member->value]);
    $administrator->switchTeam($team);
    $collaborator->switchTeam($team);
    $otherCollaborator->switchTeam($team);

    return [$administrator, $collaborator, $otherCollaborator, $team];
}

test('assigning a roadmap grants its course permissions and learner visibility', function () {
    [$administrator, $collaborator, $otherCollaborator, $team] = roadmapAssignmentUsers();
    $course = Course::factory()->for($team)->published()->create([
        'access_mode' => CourseAccessMode::Assigned,
    ]);
    $roadmap = Roadmap::factory()->for($team)->published()->create(['title' => 'Inspector nivel 1']);
    $roadmap->items()->create(['course_id' => $course->id, 'position' => 1]);

    $assignment = app(AssignRoadmapToCollaborator::class)->handle(
        $administrator,
        $collaborator,
        $roadmap,
    );

    $enrollment = CourseEnrollment::query()->where('course_id', $course->id)->where('user_id', $collaborator->id)->sole();

    expect($assignment->status)->toBe(RoadmapAssignmentStatus::Active)
        ->and($enrollment->status)->toBe(EnrollmentStatus::Active)
        ->and($enrollment->roadmap_assignment_id)->toBe($assignment->id);

    $this->actingAs($collaborator)
        ->get(route('roadmaps.show', ['current_team' => $team, 'roadmap' => $roadmap]))
        ->assertOk()
        ->assertSee($roadmap->title)
        ->assertSee($course->title);

    $this->actingAs($otherCollaborator)
        ->get(route('roadmaps.show', ['current_team' => $team, 'roadmap' => $roadmap]))
        ->assertForbidden();
});

test('cancelling a roadmap only cancels course access created by that roadmap', function () {
    [$administrator, $collaborator, , $team] = roadmapAssignmentUsers();
    $roadmapCourse = Course::factory()->for($team)->published()->create([
        'access_mode' => CourseAccessMode::Assigned,
    ]);
    $directCourse = Course::factory()->for($team)->published()->create([
        'access_mode' => CourseAccessMode::Assigned,
    ]);
    $roadmap = Roadmap::factory()->for($team)->published()->create();
    $roadmap->items()->create(['course_id' => $roadmapCourse->id, 'position' => 1]);
    $roadmap->items()->create(['course_id' => $directCourse->id, 'position' => 2]);
    $directEnrollment = CourseEnrollment::factory()->for($directCourse)->for($collaborator)->create();

    $assignment = app(AssignRoadmapToCollaborator::class)->handle($administrator, $collaborator, $roadmap);
    app(CancelRoadmapAssignment::class)->handle($administrator, $assignment);

    $roadmapEnrollment = CourseEnrollment::query()
        ->where('course_id', $roadmapCourse->id)
        ->where('user_id', $collaborator->id)
        ->sole();

    expect($assignment->fresh()->status)->toBe(RoadmapAssignmentStatus::Cancelled)
        ->and($roadmapEnrollment->status)->toBe(EnrollmentStatus::Cancelled)
        ->and($directEnrollment->fresh()->status)->toBe(EnrollmentStatus::Active)
        ->and($directEnrollment->fresh()->roadmap_assignment_id)->toBeNull();
});

test('a roadmap assignment makes its published exam visible', function () {
    [$administrator, $collaborator, , $team] = roadmapAssignmentUsers();
    $exam = Exam::factory()->for($team)->published()->create(['title' => 'Evaluación final del roadmap']);
    $roadmap = Roadmap::factory()->for($team)->published()->create();
    $roadmap->items()->create(['exam_id' => $exam->id, 'position' => 1]);

    app(AssignRoadmapToCollaborator::class)->handle($administrator, $collaborator, $roadmap);

    $this->actingAs($collaborator)
        ->get(route('exams.index', ['current_team' => $team]))
        ->assertOk()
        ->assertSee($exam->title);
});

test('course exams appear only after the collaborator completes the course', function () {
    [, $collaborator, , $team] = roadmapAssignmentUsers();
    $course = Course::factory()->for($team)->published()->create();
    $exam = Exam::factory()->for($team)->published()->create(['title' => 'Examen posterior al curso']);
    $course->exams()->attach($exam);
    $enrollment = CourseEnrollment::factory()->for($course)->for($collaborator)->create();

    $this->actingAs($collaborator)
        ->get(route('exams.index', ['current_team' => $team]))
        ->assertDontSee($exam->title);

    $enrollment->update(['status' => EnrollmentStatus::Completed, 'completed_at' => now()]);

    $this->actingAs($collaborator)
        ->get(route('exams.index', ['current_team' => $team]))
        ->assertSee($exam->title);
});
