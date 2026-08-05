<?php

use App\Enums\TeamRole;
use App\Models\Course;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\C89OrganizationSeeder;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed(C89OrganizationSeeder::class);

    $this->team = Team::query()->where('slug', 'c89')->firstOrFail();
    $this->administrator = User::query()->where('email', 'admin@c89.com.mx')->firstOrFail();
    $this->collaborator = User::query()->where('email', 'colaborador@c89.com.mx')->firstOrFail();
});

test('an administrator can manage courses in the current organization', function () {
    $course = Course::factory()->for($this->team)->create();

    expect(Gate::forUser($this->administrator)->allows('create', Course::class))->toBeTrue()
        ->and(Gate::forUser($this->administrator)->allows('view', $course))->toBeTrue()
        ->and(Gate::forUser($this->administrator)->allows('update', $course))->toBeTrue()
        ->and(Gate::forUser($this->administrator)->allows('publish', $course))->toBeTrue()
        ->and(Gate::forUser($this->administrator)->allows('delete', $course))->toBeTrue()
        ->and(Gate::forUser($this->administrator)->allows('restore', $course))->toBeTrue()
        ->and(Gate::forUser($this->administrator)->allows('forceDelete', $course))->toBeFalse();
});

test('a collaborator can view published courses but cannot manage them', function () {
    $draftCourse = Course::factory()->for($this->team)->create();
    $publishedCourse = Course::factory()->for($this->team)->published()->create();

    expect(Gate::forUser($this->collaborator)->allows('viewAny', Course::class))->toBeTrue()
        ->and(Gate::forUser($this->collaborator)->allows('view', $publishedCourse))->toBeTrue()
        ->and(Gate::forUser($this->collaborator)->allows('view', $draftCourse))->toBeFalse()
        ->and(Gate::forUser($this->collaborator)->allows('create', Course::class))->toBeFalse()
        ->and(Gate::forUser($this->collaborator)->allows('update', $publishedCourse))->toBeFalse()
        ->and(Gate::forUser($this->collaborator)->allows('publish', $publishedCourse))->toBeFalse()
        ->and(Gate::forUser($this->collaborator)->allows('delete', $publishedCourse))->toBeFalse();
});

test('an administrator cannot manage a course outside the current organization', function () {
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($this->administrator, ['role' => TeamRole::Admin->value]);
    $otherCourse = Course::factory()->for($otherTeam)->create();

    expect($this->administrator->isCurrentTeam($this->team))->toBeTrue()
        ->and(Gate::forUser($this->administrator)->allows('view', $otherCourse))->toBeFalse()
        ->and(Gate::forUser($this->administrator)->allows('update', $otherCourse))->toBeFalse()
        ->and(Gate::forUser($this->administrator)->allows('delete', $otherCourse))->toBeFalse();
});
