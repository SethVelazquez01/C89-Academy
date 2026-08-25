<?php

use App\Actions\Exams\StartExamAttempt;
use App\Actions\Exams\SubmitExamAttempt;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamAttemptEventType;
use App\Enums\ExamAttemptStatus;
use App\Enums\ExamQuestionType;
use App\Enums\TeamRole;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function examAttemptContext(): array
{
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);
    $administrator->switchTeam($team);
    $collaborator->switchTeam($team);

    $course = Course::factory()->for($team)->published()->create();
    CourseEnrollment::factory()->completed()->for($course)->for($collaborator)->create([
        'status' => EnrollmentStatus::Completed,
    ]);
    $exam = Exam::factory()->for($team)->published()->create([
        'max_attempts' => 1,
        'randomize_questions' => false,
        'randomize_options' => false,
        'passing_score' => 60,
    ]);
    $course->exams()->attach($exam);
    $exam->questions()->create([
        'type' => ExamQuestionType::SingleChoice,
        'prompt' => '¿Cuál es la respuesta correcta?',
        'options' => ['Correcta', 'Incorrecta'],
        'correct_answer' => 'Correcta',
        'points' => 2,
        'position' => 1,
    ]);
    $exam->questions()->create([
        'type' => ExamQuestionType::TrueFalse,
        'prompt' => 'La inspección debe documentarse.',
        'options' => ['Verdadero', 'Falso'],
        'correct_answer' => 'Verdadero',
        'points' => 1,
        'position' => 2,
    ]);

    return [$administrator, $collaborator, $team, $exam];
}

test('only collaborators with an assigned exam can start an attempt', function () {
    [, $collaborator, , $exam] = examAttemptContext();
    $unassigned = User::factory()->create();
    $exam->team->members()->attach($unassigned, ['role' => TeamRole::Member->value]);
    $unassigned->switchTeam($exam->team);

    $attempt = app(StartExamAttempt::class)->handle($collaborator, $exam);

    expect($attempt->user_id)->toBe($collaborator->id)
        ->and($attempt->status)->toBe(ExamAttemptStatus::InProgress)
        ->and($attempt->questions()->count())->toBe(2)
        ->and($attempt->max_points)->toBe(3);

    app(StartExamAttempt::class)->handle($unassigned, $exam);
})->throws(AuthorizationException::class);

test('starting again resumes the single active attempt', function () {
    [, $collaborator, , $exam] = examAttemptContext();

    $first = app(StartExamAttempt::class)->handle($collaborator, $exam);
    $second = app(StartExamAttempt::class)->handle($collaborator, $exam);

    expect($second->is($first))->toBeTrue()
        ->and(ExamAttempt::query()->count())->toBe(1);
});

test('closed questions are graded automatically on submission', function () {
    [, $collaborator, , $exam] = examAttemptContext();
    $attempt = app(StartExamAttempt::class)->handle($collaborator, $exam);
    $questions = $attempt->questions()->get();
    $questions[0]->update(['answer' => 'Correcta', 'answered_at' => now()]);
    $questions[1]->update(['answer' => 'Falso', 'answered_at' => now()]);

    $result = app(SubmitExamAttempt::class)->handle($collaborator, $attempt);

    expect($result->status)->toBe(ExamAttemptStatus::Passed)
        ->and($result->earned_points)->toBe(2)
        ->and($result->percentage_score)->toBe(66.67)
        ->and($questions[0]->fresh()->is_correct)->toBeTrue()
        ->and($questions[1]->fresh()->is_correct)->toBeFalse();
});

test('the maximum number of attempts is enforced by the server', function () {
    [, $collaborator, , $exam] = examAttemptContext();
    $attempt = app(StartExamAttempt::class)->handle($collaborator, $exam);
    app(SubmitExamAttempt::class)->handle($collaborator, $attempt);

    app(StartExamAttempt::class)->handle($collaborator, $exam);
})->throws(ValidationException::class, 'Ya utilizaste todos los intentos');

test('expired attempts are submitted automatically', function () {
    [, $collaborator, , $exam] = examAttemptContext();
    $attempt = app(StartExamAttempt::class)->handle($collaborator, $exam);
    $attempt->update(['expires_at' => now()->subSecond()]);

    Livewire::actingAs($collaborator)
        ->test('pages::exams.take', ['exam' => $exam->slug, 'examAttempt' => $attempt->uuid]);

    expect($attempt->fresh()->timed_out)->toBeTrue()
        ->and($attempt->fresh()->status)->toBe(ExamAttemptStatus::Failed)
        ->and($attempt->fresh()->submitted_at)->not->toBeNull();
});

test('answers autosave and security signals are recorded', function () {
    [, $collaborator, , $exam] = examAttemptContext();
    $attempt = app(StartExamAttempt::class)->handle($collaborator, $exam);

    Livewire::actingAs($collaborator)
        ->test('pages::exams.take', ['exam' => $exam->slug, 'examAttempt' => $attempt->uuid])
        ->set('answer', 'Correcta')
        ->call('recordSecurityEvent', ExamAttemptEventType::TabHidden->value)
        ->assertHasNoErrors();

    expect($attempt->questions()->first()->answer)->toBe('Correcta')
        ->and($attempt->events()->count())->toBe(1)
        ->and($attempt->fresh()->security_event_count)->toBe(1);
});
