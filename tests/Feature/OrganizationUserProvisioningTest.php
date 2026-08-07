<?php

use App\Actions\Teams\ProvisionOrganizationUser;
use App\Enums\MembershipStatus;
use App\Enums\PlatformRole;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\OrganizationAccountCreated;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('un administrador crea un colaborador con acceso seguro y auditoría', function () {
    Notification::fake();

    $administrator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    $membership = app(ProvisionOrganizationUser::class)->handle(
        $administrator,
        $team,
        'Nueva Colaboradora',
        'nueva@example.com',
        TeamRole::Member->value,
    );

    $user = User::query()->where('email', 'nueva@example.com')->firstOrFail();

    expect($membership->user_id)->toBe($user->id)
        ->and($membership->team_id)->toBe($team->id)
        ->and($membership->role)->toBe(TeamRole::Member)
        ->and($membership->status)->toBe(MembershipStatus::Active)
        ->and($membership->created_by)->toBe($administrator->id)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->personalTeam())->not->toBeNull()
        ->and($user->current_team_id)->toBe($team->id);

    Notification::assertSentTo(
        $user,
        OrganizationAccountCreated::class,
        fn (OrganizationAccountCreated $notification): bool => $notification->team->is($team)
            && $notification->role === TeamRole::Member
            && $notification->toMail($user)->subject === 'Configura tu acceso a C89 Academy'
            && $notification->toMail($user)->actionText === 'Establecer mi contraseña',
    );
});

test('el Platform Owner crea administradores sin convertirse en miembro', function () {
    Notification::fake();

    $platformOwner = User::factory()->create(['platform_role' => PlatformRole::Owner]);
    $team = Team::factory()->create();

    $membership = app(ProvisionOrganizationUser::class)->handle(
        $platformOwner,
        $team,
        'Administradora Global',
        'admin-global@example.com',
        TeamRole::Admin->value,
    );

    $user = User::query()->where('email', 'admin-global@example.com')->firstOrFail();

    expect($membership->role)->toBe(TeamRole::Admin)
        ->and($membership->created_by)->toBe($platformOwner->id)
        ->and($platformOwner->belongsToTeam($team))->toBeFalse()
        ->and($user->teamRole($team))->toBe(TeamRole::Admin);

    Notification::assertSentTo($user, OrganizationAccountCreated::class);
});

test('una identidad existente se reutiliza sin modificar datos globales ni enviar restablecimiento', function () {
    Notification::fake();

    $administrator = User::factory()->create();
    $existingUser = User::factory()->create([
        'name' => 'Nombre Global',
        'email' => 'existente@example.com',
    ]);
    $currentTeam = $existingUser->currentTeam;
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    $membership = app(ProvisionOrganizationUser::class)->handle(
        $administrator,
        $team,
        'Nombre Intentado',
        'EXISTENTE@example.com',
        TeamRole::Member->value,
    );

    expect($membership->user_id)->toBe($existingUser->id)
        ->and($existingUser->fresh()->name)->toBe('Nombre Global')
        ->and($existingUser->fresh()->current_team_id)->toBe($currentTeam?->id);

    Notification::assertNothingSent();
    $this->assertDatabaseCount('users', 2);
});

test('una membresía removida se reincorpora reutilizando su fila auditada', function () {
    Notification::fake();

    $administrator = User::factory()->create();
    $returningUser = User::factory()->create(['email' => 'regresa@example.com']);
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($returningUser, [
        'role' => TeamRole::Member->value,
        'status' => MembershipStatus::Removed->value,
    ]);

    $originalMembership = Membership::query()
        ->where('team_id', $team->id)
        ->where('user_id', $returningUser->id)
        ->firstOrFail();

    $membership = app(ProvisionOrganizationUser::class)->handle(
        $administrator,
        $team,
        'Nombre Ignorado',
        $returningUser->email,
        TeamRole::Admin->value,
    );

    expect($membership->id)->toBe($originalMembership->id)
        ->and($membership->status)->toBe(MembershipStatus::Active)
        ->and($membership->role)->toBe(TeamRole::Admin)
        ->and($membership->status_changed_by)->toBe($administrator->id)
        ->and($membership->role_changed_by)->toBe($administrator->id);

    Notification::assertNothingSent();
    $this->assertDatabaseCount('team_members', 4);
});

test('colaboradores y administradores externos no pueden crear membresías', function () {
    $collaborator = User::factory()->create();
    $externalAdministrator = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);
    $otherTeam->members()->attach($externalAdministrator, ['role' => TeamRole::Admin->value]);

    foreach ([$collaborator, $externalAdministrator] as $actor) {
        expect(fn () => app(ProvisionOrganizationUser::class)->handle(
            $actor,
            $team,
            'Usuario Manipulado',
            fake()->unique()->safeEmail(),
            TeamRole::Member->value,
        ))->toThrow(AuthorizationException::class);
    }

    $this->assertDatabaseCount('users', 2);
});

test('el Platform Owner no puede crear colaboradores organizacionales', function () {
    $platformOwner = User::factory()->create(['platform_role' => PlatformRole::Owner]);
    $team = Team::factory()->create();

    expect(fn () => app(ProvisionOrganizationUser::class)->handle(
        $platformOwner,
        $team,
        'Colaborador Inválido',
        'colaborador-invalido@example.com',
        TeamRole::Member->value,
    ))->toThrow(ValidationException::class, 'El Platform Owner solo puede crear administradores organizacionales.');

    $this->assertDatabaseMissing('users', ['email' => 'colaborador-invalido@example.com']);
});

test('no se puede crear una membresía sin cupo disponible', function () {
    $administrator = User::factory()->create();
    $existingCollaborator = User::factory()->create();
    $team = Team::factory()->create([
        'included_collaborator_seats' => 1,
        'additional_collaborator_seats' => 0,
    ]);
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($existingCollaborator, ['role' => TeamRole::Member->value]);

    expect(fn () => app(ProvisionOrganizationUser::class)->handle(
        $administrator,
        $team,
        'Sin Cupo',
        'sin-cupo@example.com',
        TeamRole::Member->value,
    ))->toThrow(ValidationException::class, 'No hay cupos disponibles para Colaborador en esta organización.');

    $this->assertDatabaseMissing('users', ['email' => 'sin-cupo@example.com']);
});

test('una invitación pendiente impide crear la misma membresía directamente', function () {
    $administrator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'pendiente@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $administrator->id,
    ]);

    expect(fn () => app(ProvisionOrganizationUser::class)->handle(
        $administrator,
        $team,
        'Invitación Pendiente',
        'pendiente@example.com',
        TeamRole::Member->value,
    ))->toThrow(ValidationException::class, 'Este correo ya tiene una invitación pendiente en la organización.');
});

test('no se puede crear dos veces una membresía activa o suspendida', function () {
    $administrator = User::factory()->create();
    $existingUser = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($existingUser, [
        'role' => TeamRole::Member->value,
        'status' => MembershipStatus::Suspended->value,
    ]);

    expect(fn () => app(ProvisionOrganizationUser::class)->handle(
        $administrator,
        $team,
        'Usuario Existente',
        $existingUser->email,
        TeamRole::Member->value,
    ))->toThrow(ValidationException::class, 'Este usuario ya pertenece actualmente a la organización.');
});

test('el administrador crea usuarios desde el CRUD de su organización', function () {
    Notification::fake();

    $administrator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    Livewire::actingAs($administrator)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertSee('Crear usuario')
        ->assertSeeHtml('data-test="create-organization-user-button"')
        ->set('newUserName', 'Colaborador desde CRUD')
        ->set('newUserEmail', 'crud@example.com')
        ->set('newUserRole', TeamRole::Member->value)
        ->call('createOrganizationUser')
        ->assertHasNoErrors()
        ->assertSee('Colaborador desde CRUD')
        ->assertSee('crud@example.com');

    $createdUser = User::query()->where('email', 'crud@example.com')->firstOrFail();

    expect($createdUser->teamRole($team))->toBe(TeamRole::Member);
    Notification::assertSentTo($createdUser, OrganizationAccountCreated::class);
});

test('el colaborador no ve ni puede usar el alta directa de usuarios', function () {
    $collaborator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);

    Livewire::actingAs($collaborator)
        ->test('pages::teams.edit', ['team' => $team])
        ->assertDontSeeHtml('data-test="create-organization-user-button"')
        ->set('newUserName', 'Usuario Manipulado')
        ->set('newUserEmail', 'manipulado@example.com')
        ->set('newUserRole', TeamRole::Member->value)
        ->call('createOrganizationUser')
        ->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'manipulado@example.com']);
});

test('el Platform Owner consulta miembros, invitaciones y cupos de una organización', function () {
    $platformOwner = User::factory()->create(['platform_role' => PlatformRole::Owner]);
    $administrator = User::factory()->create();
    $suspendedCollaborator = User::factory()->create();
    $team = Team::factory()->create([
        'included_administrator_seats' => 3,
        'included_collaborator_seats' => 4,
    ]);
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($suspendedCollaborator, [
        'role' => TeamRole::Member->value,
        'status' => MembershipStatus::Suspended->value,
    ]);
    TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invitacion-visible@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $administrator->id,
    ]);

    Livewire::actingAs($platformOwner)
        ->test('pages::teams.index')
        ->assertSeeHtml('data-test="platform-organization-view-members"')
        ->call('showOrganizationMembersModal', $team->id)
        ->assertSet('viewingOrganizationId', $team->id)
        ->assertSee('Usuarios de '.$team->name)
        ->assertSee($administrator->name)
        ->assertSee($suspendedCollaborator->email)
        ->assertSee('Suspendida')
        ->assertSee('invitacion-visible@example.com')
        ->assertSee('1 de 3 ocupados')
        ->assertSee('2 de 4 ocupados')
        ->assertSeeHtml('data-test="platform-organization-members-list"');

    expect($platformOwner->belongsToTeam($team))->toBeFalse();
});

test('el Platform Owner crea otro administrador desde la vista global', function () {
    Notification::fake();

    $platformOwner = User::factory()->create(['platform_role' => PlatformRole::Owner]);
    $existingAdministrator = User::factory()->create();
    $team = Team::factory()->create([
        'included_administrator_seats' => 2,
    ]);
    $team->members()->attach($existingAdministrator, ['role' => TeamRole::Admin->value]);

    Livewire::actingAs($platformOwner)
        ->test('pages::teams.index')
        ->call('showOrganizationMembersModal', $team->id)
        ->set('ownerAdministratorName', 'Segundo Administrador')
        ->set('ownerAdministratorEmail', 'segundo-admin@example.com')
        ->call('createOrganizationAdministrator')
        ->assertHasNoErrors()
        ->assertSee('Segundo Administrador')
        ->assertSee('segundo-admin@example.com');

    $createdAdministrator = User::query()->where('email', 'segundo-admin@example.com')->firstOrFail();

    expect($createdAdministrator->teamRole($team))->toBe(TeamRole::Admin)
        ->and($platformOwner->belongsToTeam($team))->toBeFalse();
    Notification::assertSentTo($createdAdministrator, OrganizationAccountCreated::class);
});

test('usuarios regulares no pueden abrir la vista global de miembros', function () {
    $regularUser = User::factory()->create();
    $team = Team::factory()->create();

    Livewire::actingAs($regularUser)
        ->test('pages::teams.index')
        ->call('showOrganizationMembersModal', $team->id)
        ->assertForbidden();
});
