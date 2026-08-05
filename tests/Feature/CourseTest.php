<?php

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\Team;
use App\Models\User;

test('a course belongs to an organization and can keep its creator', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $course = Course::factory()
        ->for($team)
        ->for($user, 'creator')
        ->create([
            'title' => 'Inducción C89',
            'status' => CourseStatus::Draft,
        ]);

    expect($course->team->is($team))->toBeTrue()
        ->and($course->creator?->is($user))->toBeTrue()
        ->and($course->status)->toBe(CourseStatus::Draft)
        ->and($course->slug)->toBe('induccion-c89')
        ->and($team->courses->first()?->is($course))->toBeTrue()
        ->and($user->createdCourses->first()?->is($course))->toBeTrue();
});

test('course slugs are unique within an organization', function () {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $firstCourse = Course::factory()->for($team)->create(['title' => 'Seguridad Operativa']);
    $secondCourse = Course::factory()->for($team)->create(['title' => 'Seguridad Operativa']);
    $otherTeamCourse = Course::factory()->for($otherTeam)->create(['title' => 'Seguridad Operativa']);

    expect($firstCourse->slug)->toBe('seguridad-operativa')
        ->and($secondCourse->slug)->toBe('seguridad-operativa-2')
        ->and($otherTeamCourse->slug)->toBe('seguridad-operativa');
});

test('a published course records its publication state', function () {
    $course = Course::factory()->published()->create();

    expect($course->status)->toBe(CourseStatus::Published)
        ->and($course->published_at)->not->toBeNull();
});

test('a course can be soft deleted', function () {
    $course = Course::factory()->create();

    $course->delete();

    $this->assertSoftDeleted('courses', ['id' => $course->id]);
});
