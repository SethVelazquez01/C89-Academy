<?php

use App\Actions\Exams\ReviewExamAnswer;
use App\Actions\Exams\StartExamAttempt;
use App\Actions\Exams\SubmitExamAttempt;
use App\Enums\ExamAttemptStatus;
use App\Enums\ExamQuestionType;
use App\Enums\RoadmapAssignmentStatus;
use App\Enums\TeamRole;
use App\Models\Exam;
use App\Models\Roadmap;
use App\Models\RoadmapAssignment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

function examReviewContext(): array
{
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);
    $administrator->switchTeam($team);
    $collaborator->switchTeam($team);

    $exam = Exam::factory()->for($team)->published()->create([
        'passing_score' => 70,
        'randomize_questions' => false,
    ]);
    $exam->questions()->create([
        'type' => ExamQuestionType::ShortAnswer,
        'prompt' => 'Explica la decisión segura.',
        'options' => null,
        'correct_answer' => null,
        'points' => 5,
        'position' => 1,
    ]);
    $roadmap = Roadmap::factory()->for($team)->published()->create();
    $roadmap->items()->create(['exam_id' => $exam->id, 'position' => 1]);
    RoadmapAssignment::factory()->for($roadmap)->for($collaborator)->create([
        'assigned_by' => $administrator->id,
        'status' => RoadmapAssignmentStatus::Active,
    ]);

    return [$administrator, $collaborator, $team, $exam];
}

test('open answers remain pending until an administrator reviews them', function () {
    [$administrator, $collaborator, , $exam] = examReviewContext();
    $attempt = app(StartExamAttempt::class)->handle($collaborator, $exam);
    $question = $attempt->questions()->sole();
    $question->update(['answer' => 'Detendría la operación y documentaría el riesgo.', 'answered_at' => now()]);

    $submitted = app(SubmitExamAttempt::class)->handle($collaborator, $attempt);

    expect($submitted->status)->toBe(ExamAttemptStatus::PendingReview)
        ->and($submitted->percentage_score)->toBeNull();

    $reviewed = app(ReviewExamAnswer::class)->handle(
        $administrator,
        $question,
        4,
        'Respuesta clara y bien fundamentada.',
    );

    expect($reviewed->status)->toBe(ExamAttemptStatus::Passed)
        ->and($reviewed->percentage_score)->toBe(80.0)
        ->and($question->fresh()->reviewed_by)->toBe($administrator->id)
        ->and($question->fresh()->review_comment)->toBe('Respuesta clara y bien fundamentada.');
});

test('administrators can open the review interface', function () {
    [$administrator, $collaborator, $team, $exam] = examReviewContext();
    $attempt = app(StartExamAttempt::class)->handle($collaborator, $exam);
    $question = $attempt->questions()->sole();
    $question->update(['answer' => 'Respuesta abierta', 'answered_at' => now()]);
    app(SubmitExamAttempt::class)->handle($collaborator, $attempt);

    Livewire::actingAs($administrator)
        ->test('pages::admin.exams.review', ['exam' => $exam->slug, 'examAttempt' => $attempt->uuid])
        ->assertSee($collaborator->name)
        ->assertSee('Respuesta abierta');

    $this->actingAs($administrator)
        ->get(route('admin.exams.attempts', ['current_team' => $team, 'exam' => $exam]))
        ->assertOk()
        ->assertSee('Pendiente de revisión');
});

test('collaborators cannot review answers', function () {
    [, $collaborator, , $exam] = examReviewContext();
    $attempt = app(StartExamAttempt::class)->handle($collaborator, $exam);
    $question = $attempt->questions()->sole();
    $question->update(['answer' => 'Respuesta abierta', 'answered_at' => now()]);
    app(SubmitExamAttempt::class)->handle($collaborator, $attempt);

    app(ReviewExamAnswer::class)->handle($collaborator, $question, 5);
})->throws(AuthorizationException::class);

test('the final result page is visible only to the owner or a reviewer', function () {
    [$administrator, $collaborator, $team, $exam] = examReviewContext();
    $attempt = app(StartExamAttempt::class)->handle($collaborator, $exam);
    app(SubmitExamAttempt::class)->handle($collaborator, $attempt);

    $this->actingAs($collaborator)
        ->get(route('exams.result', ['current_team' => $team, 'examAttempt' => $attempt]))
        ->assertOk();

    $this->actingAs($administrator)
        ->get(route('exams.result', ['current_team' => $team, 'examAttempt' => $attempt]))
        ->assertOk();
});
