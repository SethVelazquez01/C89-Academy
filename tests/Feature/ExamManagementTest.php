<?php

use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\TeamRole;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Exam;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

function examAdministrator(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $user->switchTeam($team);

    return [$user, $team];
}

test('administrators can open exam management from their current organization', function () {
    [$administrator, $team] = examAdministrator();

    $this->actingAs($administrator)
        ->get(route('admin.exams.index', ['current_team' => $team]))
        ->assertOk()
        ->assertSeeHtml('data-test="admin-exams-index"')
        ->assertSee('Crear examen');
});

test('an administrator can create an isolated exam draft', function () {
    [$administrator, $team] = examAdministrator();

    Livewire::actingAs($administrator)
        ->test('pages::admin.exams.create')
        ->set('title', 'Certificación Inspector nivel 1')
        ->set('summary', 'Valida decisiones de inspección.')
        ->call('save')
        ->assertHasNoErrors();

    $exam = Exam::query()->sole();

    expect($exam->team_id)->toBe($team->id)
        ->and($exam->status)->toBe(ExamStatus::Draft)
        ->and($exam->passing_score)->toBe(80);
});

test('an exam requires a question before it can be published', function () {
    [$administrator, $team] = examAdministrator();
    $exam = Exam::factory()->for($team)->create();

    Livewire::actingAs($administrator)
        ->test('pages::admin.exams.edit', ['exam' => $exam])
        ->call('togglePublished')
        ->assertHasErrors(['publication']);

    expect($exam->fresh()->status)->toBe(ExamStatus::Draft);
});

test('an administrator can add a question and publish the exam', function () {
    [$administrator, $team] = examAdministrator();
    $exam = Exam::factory()->for($team)->create();

    Livewire::actingAs($administrator)
        ->test('pages::admin.exams.edit', ['exam' => $exam])
        ->set('questionType', ExamQuestionType::SingleChoice->value)
        ->set('questionPrompt', '¿Cuál es la primera acción segura?')
        ->set('questionOptions', ['Detener la operación', 'Continuar y reportar después', 'Ignorar la condición'])
        ->set('correctOptionIndex', 0)
        ->call('saveQuestion')
        ->assertHasNoErrors()
        ->call('togglePublished')
        ->assertHasNoErrors();

    expect($exam->questions()->count())->toBe(1)
        ->and($exam->fresh()->status)->toBe(ExamStatus::Published)
        ->and($exam->fresh()->published_at)->not->toBeNull();
});

test('open questions are stored for manual review without a correct answer', function () {
    [$administrator, $team] = examAdministrator();
    $exam = Exam::factory()->for($team)->create();

    Livewire::actingAs($administrator)
        ->test('pages::admin.exams.edit', ['exam' => $exam])
        ->set('questionType', ExamQuestionType::ShortAnswer->value)
        ->set('questionPrompt', 'Explica por qué detendrías la operación.')
        ->set('questionPoints', 5)
        ->call('saveQuestion')
        ->assertHasNoErrors();

    $question = $exam->questions()->sole();

    expect($question->type)->toBe(ExamQuestionType::ShortAnswer)
        ->and($question->correct_answer)->toBeNull()
        ->and($question->options)->toBeNull()
        ->and($question->points)->toBe(5);
});

test('administrators can edit an existing question from the builder', function () {
    [$administrator, $team] = examAdministrator();
    $exam = Exam::factory()->for($team)->create();
    $question = $exam->questions()->create([
        'type' => ExamQuestionType::TrueFalse,
        'prompt' => 'La inspección puede omitirse.',
        'options' => ['Verdadero', 'Falso'],
        'correct_answer' => 'Falso',
        'points' => 1,
        'position' => 1,
    ]);

    Livewire::actingAs($administrator)
        ->test('pages::admin.exams.edit', ['exam' => $exam])
        ->call('editQuestion', $question->id)
        ->assertSet('editingQuestionId', $question->id)
        ->set('questionPrompt', 'La inspección documentada puede omitirse.')
        ->set('trueFalseAnswer', 'Verdadero')
        ->call('saveQuestion')
        ->assertHasNoErrors();

    expect($question->fresh()->prompt)->toBe('La inspección documentada puede omitirse.')
        ->and($question->fresh()->correct_answer)->toBe('Verdadero');
});

test('collaborators only see published exams from their current organization', function () {
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);
    $collaborator->switchTeam($team);

    $published = Exam::factory()->for($team)->published()->create(['title' => 'Examen visible']);
    $draft = Exam::factory()->for($team)->create(['title' => 'Examen privado']);
    $other = Exam::factory()->for(Team::factory())->published()->create(['title' => 'Examen ajeno']);
    $course = Course::factory()->for($team)->published()->create();
    $course->exams()->attach($published);
    CourseEnrollment::factory()->completed()->for($course)->for($collaborator)->create();

    $this->actingAs($collaborator)
        ->get(route('exams.index', ['current_team' => $team]))
        ->assertOk()
        ->assertSee($published->title)
        ->assertDontSee($draft->title)
        ->assertDontSee($other->title);

    expect(Gate::forUser($collaborator)->allows('create', Exam::class))->toBeFalse();
});

test('administrators cannot manage exams from another organization', function () {
    [$administrator] = examAdministrator();
    $otherExam = Exam::factory()->for(Team::factory())->create();

    expect(Gate::forUser($administrator)->allows('update', $otherExam))->toBeFalse()
        ->and(Gate::forUser($administrator)->allows('publish', $otherExam))->toBeFalse()
        ->and(Gate::forUser($administrator)->allows('delete', $otherExam))->toBeFalse();
});
