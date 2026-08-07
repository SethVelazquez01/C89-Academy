<?php

use App\Actions\Teams\CreateSeatIncreaseRequest;
use App\Actions\Teams\ReviewSeatIncreaseRequest;
use App\Enums\PlatformRole;
use App\Enums\SeatIncreaseRequestStatus;
use App\Enums\TeamRole;
use App\Models\SeatIncreaseRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('un administrador solicita cupos adicionales con auditoría', function () {
    $administrator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    $request = app(CreateSeatIncreaseRequest::class)->handle(
        $administrator,
        $team,
        2,
        8,
        'Necesitamos ampliar el equipo durante el siguiente trimestre.',
    );

    expect($request->team_id)->toBe($team->id)
        ->and($request->requested_by)->toBe($administrator->id)
        ->and($request->requested_administrator_seats)->toBe(2)
        ->and($request->requested_collaborator_seats)->toBe(8)
        ->and($request->status)->toBe(SeatIncreaseRequestStatus::Pending)
        ->and($request->pending_marker)->toBeTrue()
        ->and($request->reviewed_by)->toBeNull()
        ->and($request->reviewed_at)->toBeNull();
});

test('una organización solo puede tener una solicitud pendiente', function () {
    $administrator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    app(CreateSeatIncreaseRequest::class)->handle(
        $administrator,
        $team,
        1,
        0,
        'Necesitamos un administrador adicional para operaciones.',
    );

    expect(fn () => app(CreateSeatIncreaseRequest::class)->handle(
        $administrator,
        $team,
        0,
        5,
        'Necesitamos colaboradores adicionales para operaciones.',
    ))->toThrow(ValidationException::class, 'Esta organización ya tiene una solicitud de cupos pendiente.');

    $this->assertDatabaseCount('seat_increase_requests', 1);
});

test('la solicitud requiere al menos un cupo y una justificación suficiente', function () {
    $administrator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    expect(fn () => app(CreateSeatIncreaseRequest::class)->handle(
        $administrator,
        $team,
        0,
        0,
        'Justificación válida.',
    ))->toThrow(ValidationException::class, 'Debes solicitar al menos un cupo adicional.');

    expect(fn () => app(CreateSeatIncreaseRequest::class)->handle(
        $administrator,
        $team,
        1,
        0,
        'Corta',
    ))->toThrow(ValidationException::class, 'La justificación debe tener al menos 10 caracteres.');
});

test('colaboradores y actores externos no pueden solicitar cupos', function () {
    $collaborator = User::factory()->create();
    $externalAdministrator = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);
    $otherTeam->members()->attach($externalAdministrator, ['role' => TeamRole::Admin->value]);

    foreach ([$collaborator, $externalAdministrator] as $actor) {
        expect(fn () => app(CreateSeatIncreaseRequest::class)->handle(
            $actor,
            $team,
            1,
            1,
            'Solicitud manipulada para otra organización.',
        ))->toThrow(AuthorizationException::class);
    }

    $this->assertDatabaseCount('seat_increase_requests', 0);
});

test('no se pueden solicitar cupos para equipos personales', function () {
    $owner = User::factory()->create();
    $personalTeam = Team::factory()->personal()->create();
    $personalTeam->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    expect(fn () => app(CreateSeatIncreaseRequest::class)->handle(
        $owner,
        $personalTeam,
        1,
        1,
        'Solicitud inválida para un equipo personal.',
    ))->toThrow(AuthorizationException::class);
});

test('el Platform Owner aprueba una solicitud e incrementa ambos cupos adicionales', function () {
    $platformOwner = User::factory()->create(['platform_role' => PlatformRole::Owner]);
    $administrator = User::factory()->create();
    $team = Team::factory()->create([
        'additional_administrator_seats' => 3,
        'additional_collaborator_seats' => 7,
    ]);
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    $request = app(CreateSeatIncreaseRequest::class)->handle(
        $administrator,
        $team,
        2,
        5,
        'Ampliación aprobable para el siguiente periodo operativo.',
    );

    $reviewedRequest = app(ReviewSeatIncreaseRequest::class)->handle(
        $platformOwner,
        $request,
        SeatIncreaseRequestStatus::Approved->value,
        'Aprobada conforme al crecimiento reportado.',
    );

    expect($reviewedRequest->status)->toBe(SeatIncreaseRequestStatus::Approved)
        ->and($reviewedRequest->pending_marker)->toBeNull()
        ->and($reviewedRequest->reviewed_by)->toBe($platformOwner->id)
        ->and($reviewedRequest->reviewed_at)->not->toBeNull()
        ->and($team->fresh()->additional_administrator_seats)->toBe(5)
        ->and($team->fresh()->additional_collaborator_seats)->toBe(12);
});

test('el rechazo exige motivo y no modifica los cupos', function () {
    $platformOwner = User::factory()->create(['platform_role' => PlatformRole::Owner]);
    $administrator = User::factory()->create();
    $team = Team::factory()->create([
        'additional_administrator_seats' => 1,
        'additional_collaborator_seats' => 2,
    ]);
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $request = app(CreateSeatIncreaseRequest::class)->handle(
        $administrator,
        $team,
        2,
        5,
        'Solicitud que será rechazada durante la revisión.',
    );

    expect(fn () => app(ReviewSeatIncreaseRequest::class)->handle(
        $platformOwner,
        $request,
        SeatIncreaseRequestStatus::Rejected->value,
    ))->toThrow(ValidationException::class, 'Debes indicar el motivo del rechazo.');

    $reviewedRequest = app(ReviewSeatIncreaseRequest::class)->handle(
        $platformOwner,
        $request,
        SeatIncreaseRequestStatus::Rejected->value,
        'La justificación todavía no acredita la necesidad operativa.',
    );

    expect($reviewedRequest->status)->toBe(SeatIncreaseRequestStatus::Rejected)
        ->and($reviewedRequest->review_notes)->toBe('La justificación todavía no acredita la necesidad operativa.')
        ->and($team->fresh()->additional_administrator_seats)->toBe(1)
        ->and($team->fresh()->additional_collaborator_seats)->toBe(2);
});

test('una solicitud revisada no puede procesarse dos veces', function () {
    $platformOwner = User::factory()->create(['platform_role' => PlatformRole::Owner]);
    $request = SeatIncreaseRequest::factory()->approved()->create();

    expect(fn () => app(ReviewSeatIncreaseRequest::class)->handle(
        $platformOwner,
        $request,
        SeatIncreaseRequestStatus::Approved->value,
    ))->toThrow(ValidationException::class, 'Esta solicitud ya fue revisada.');
});

test('usuarios organizacionales no pueden revisar solicitudes', function () {
    $administrator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $request = SeatIncreaseRequest::factory()->create([
        'team_id' => $team->id,
        'requested_by' => $administrator->id,
    ]);

    expect(fn () => app(ReviewSeatIncreaseRequest::class)->handle(
        $administrator,
        $request,
        SeatIncreaseRequestStatus::Approved->value,
    ))->toThrow(AuthorizationException::class);
});

test('un administrador envía la solicitud desde la interfaz en español', function () {
    $administrator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    Livewire::actingAs($administrator)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertSee('Solicitar cupos adicionales')
        ->set('requestedAdministratorSeats', '2')
        ->set('requestedCollaboratorSeats', '6')
        ->set('seatIncreaseJustification', 'Necesitamos incorporar un nuevo equipo de operaciones.')
        ->call('requestAdditionalSeats')
        ->assertHasNoErrors()
        ->assertSee('Solicitud de cupos pendiente')
        ->assertSee('Administradores: +2')
        ->assertSee('Colaboradores: +6')
        ->assertDontSeeHtml('data-test="request-additional-seats-button"');

    $this->assertDatabaseHas('seat_increase_requests', [
        'team_id' => $team->id,
        'requested_by' => $administrator->id,
        'requested_administrator_seats' => 2,
        'requested_collaborator_seats' => 6,
        'status' => SeatIncreaseRequestStatus::Pending->value,
    ]);
});

test('un colaborador no ve el control para solicitar cupos', function () {
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);

    Livewire::actingAs($collaborator)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertDontSee('Solicitar cupos adicionales')
        ->assertDontSeeHtml('data-test="request-additional-seats-button"');
});

test('el Platform Owner revisa y aprueba una solicitud desde su bandeja', function () {
    $platformOwner = User::factory()->create(['platform_role' => PlatformRole::Owner]);
    $administrator = User::factory()->create();
    $team = Team::factory()->create([
        'included_administrator_seats' => 2,
        'included_collaborator_seats' => 4,
        'additional_administrator_seats' => 0,
        'additional_collaborator_seats' => 0,
    ]);
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $request = SeatIncreaseRequest::factory()->create([
        'team_id' => $team->id,
        'requested_by' => $administrator->id,
        'requested_administrator_seats' => 1,
        'requested_collaborator_seats' => 3,
        'justification' => 'La organización abrirá una nueva unidad operativa.',
    ]);

    Livewire::actingAs($platformOwner)
        ->test('pages::teams.index')
        ->assertSee('Solicitudes de cupos')
        ->assertSee($team->name)
        ->assertSee('Administradores: +1')
        ->assertSee('Colaboradores: +3')
        ->call('showSeatIncreaseReviewModal', $request->id)
        ->assertSet('reviewingSeatIncreaseRequestId', $request->id)
        ->assertSee('Revisar solicitud de cupos')
        ->set('seatIncreaseReviewNotes', 'Aprobada después de revisar la necesidad operativa.')
        ->call('reviewSeatIncreaseRequest', SeatIncreaseRequestStatus::Approved->value)
        ->assertHasNoErrors()
        ->assertSee('No hay solicitudes de cupos pendientes.');

    expect($request->fresh()->status)->toBe(SeatIncreaseRequestStatus::Approved)
        ->and($team->fresh()->additional_administrator_seats)->toBe(1)
        ->and($team->fresh()->additional_collaborator_seats)->toBe(3);
});

test('el rechazo desde la interfaz exige un motivo', function () {
    $platformOwner = User::factory()->create(['platform_role' => PlatformRole::Owner]);
    $request = SeatIncreaseRequest::factory()->create();

    Livewire::actingAs($platformOwner)
        ->test('pages::teams.index')
        ->call('showSeatIncreaseReviewModal', $request->id)
        ->call('reviewSeatIncreaseRequest', SeatIncreaseRequestStatus::Rejected->value)
        ->assertHasErrors(['seatIncreaseReviewNotes'])
        ->set('seatIncreaseReviewNotes', 'La solicitud necesita una justificación más detallada.')
        ->call('reviewSeatIncreaseRequest', SeatIncreaseRequestStatus::Rejected->value)
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe(SeatIncreaseRequestStatus::Rejected)
        ->and($request->fresh()->review_notes)->toBe('La solicitud necesita una justificación más detallada.');
});

test('una organización puede solicitar nuevamente después de resolver la solicitud anterior', function () {
    $platformOwner = User::factory()->create(['platform_role' => PlatformRole::Owner]);
    $administrator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    $firstRequest = app(CreateSeatIncreaseRequest::class)->handle(
        $administrator,
        $team,
        1,
        2,
        'Primera solicitud de crecimiento para la organización.',
    );

    app(ReviewSeatIncreaseRequest::class)->handle(
        $platformOwner,
        $firstRequest,
        SeatIncreaseRequestStatus::Approved->value,
    );

    $secondRequest = app(CreateSeatIncreaseRequest::class)->handle(
        $administrator,
        $team,
        0,
        4,
        'Segunda solicitud posterior a la aprobación anterior.',
    );

    expect($secondRequest->status)->toBe(SeatIncreaseRequestStatus::Pending)
        ->and($secondRequest->pending_marker)->toBeTrue();

    $this->assertDatabaseCount('seat_increase_requests', 2);
});

test('administradores y Platform Owner ven el historial auditado de solicitudes', function () {
    $platformOwner = User::factory()->create(['platform_role' => PlatformRole::Owner]);
    $administrator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $request = SeatIncreaseRequest::factory()->approved()->create([
        'team_id' => $team->id,
        'requested_by' => $administrator->id,
        'reviewed_by' => $platformOwner->id,
        'requested_administrator_seats' => 2,
        'requested_collaborator_seats' => 3,
        'review_notes' => 'Aprobada por crecimiento acreditado.',
    ]);

    Livewire::actingAs($administrator)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertSee('Historial de solicitudes')
        ->assertSee('Aprobada')
        ->assertSee('Aprobada por crecimiento acreditado.')
        ->assertSeeHtml('data-test="seat-increase-request-history"');

    Livewire::actingAs($platformOwner)
        ->test('pages::teams.index')
        ->assertSee('Historial reciente de cupos')
        ->assertSee($request->team->name)
        ->assertSee('Aprobada por crecimiento acreditado.')
        ->assertSeeHtml('data-test="recent-seat-increase-reviews"');
});

test('los datos de solicitudes no se exponen a colaboradores', function () {
    $administrator = User::factory()->create();
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);
    SeatIncreaseRequest::factory()->create([
        'team_id' => $team->id,
        'requested_by' => $administrator->id,
        'justification' => 'Información reservada para administradores.',
    ]);

    Livewire::actingAs($collaborator)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertSet('pendingSeatIncreaseRequest', null)
        ->assertSet('seatIncreaseRequestHistory', [])
        ->assertDontSee('Información reservada para administradores.')
        ->assertDontSee('Solicitud de cupos pendiente');
});
