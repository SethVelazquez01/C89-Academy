<?php

use App\Enums\CourseAccessMode;
use App\Enums\RoadmapStatus;
use App\Enums\TeamRole;
use App\Models\Course;
use App\Models\Exam;
use App\Models\Roadmap;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

function roadmapAdministrator(): array
{
    $administrator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $administrator->switchTeam($team);

    return [$administrator, $team];
}

test('an administrator creates a roadmap with a simple first step', function () {
    [$administrator, $team] = roadmapAdministrator();

    Livewire::actingAs($administrator)
        ->test('pages::admin.roadmaps.create')
        ->set('title', 'Inspector nivel 1')
        ->set('summary', 'Ruta inicial para inspectores.')
        ->call('save')
        ->assertHasNoErrors();

    $roadmap = Roadmap::query()->sole();

    expect($roadmap->team_id)->toBe($team->id)
        ->and($roadmap->status)->toBe(RoadmapStatus::Draft);
});

test('an administrator can order courses and exams then publish a roadmap', function () {
    [$administrator, $team] = roadmapAdministrator();
    $course = Course::factory()->for($team)->published()->create([
        'access_mode' => CourseAccessMode::Assigned,
    ]);
    $exam = Exam::factory()->for($team)->published()->create();
    $roadmap = Roadmap::factory()->for($team)->create();

    Livewire::actingAs($administrator)
        ->test('pages::admin.roadmaps.edit', ['roadmap' => $roadmap->slug])
        ->set('itemType', 'course')
        ->set('contentId', $course->id)
        ->call('addItem')
        ->assertHasNoErrors()
        ->set('itemType', 'exam')
        ->set('contentId', $exam->id)
        ->call('addItem')
        ->assertHasNoErrors()
        ->call('publish')
        ->assertHasNoErrors();

    expect($roadmap->items()->count())->toBe(2)
        ->and($roadmap->items()->first()->course_id)->toBe($course->id)
        ->and($roadmap->fresh()->status)->toBe(RoadmapStatus::Published);
});

test('a roadmap cannot be published with blocked courses', function () {
    [$administrator, $team] = roadmapAdministrator();
    $blocked = Course::factory()->for($team)->published()->create([
        'access_mode' => CourseAccessMode::Blocked,
    ]);
    $roadmap = Roadmap::factory()->for($team)->create();
    $roadmap->items()->create(['course_id' => $blocked->id, 'position' => 1]);

    Livewire::actingAs($administrator)
        ->test('pages::admin.roadmaps.edit', ['roadmap' => $roadmap->slug])
        ->call('publish')
        ->assertHasErrors(['roadmap']);

    expect($roadmap->fresh()->status)->toBe(RoadmapStatus::Draft);
});

test('collaborators cannot access roadmap administration', function () {
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);
    $collaborator->switchTeam($team);

    $this->actingAs($collaborator)
        ->get(route('admin.roadmaps.index', ['current_team' => $team]))
        ->assertForbidden();
});

test('an exam can be linked to a course without duplicating it', function () {
    [$administrator, $team] = roadmapAdministrator();
    $course = Course::factory()->for($team)->create();
    $exam = Exam::factory()->for($team)->create();

    Livewire::actingAs($administrator)
        ->test('pages::admin.courses.exams', ['course' => $course->slug])
        ->set('examId', $exam->id)
        ->call('attach')
        ->assertHasNoErrors();

    expect($course->exams()->count())->toBe(1)
        ->and($course->exams()->first()->is($exam))->toBeTrue();
});
