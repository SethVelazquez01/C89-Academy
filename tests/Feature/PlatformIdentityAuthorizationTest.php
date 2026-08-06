<?php

use App\Actions\Teams\CancelInitialOrganizationAdministratorInvitation;
use App\Actions\Teams\CreateTeam;
use App\Actions\Teams\DeleteOrganization;
use App\Actions\Teams\InviteInitialOrganizationAdministrator;
use App\Actions\Teams\UpdateOrganization;
use App\Enums\PlatformRole;
use App\Enums\TeamRole;
use App\Models\Course;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Database\Seeders\C89OrganizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('the global platform role is cast separately from organization membership', function () {
    $platformOwner = User::factory()->platformOwner()->create();
    $organization = Team::factory()->create();

    expect($platformOwner->platform_role)->toBe(PlatformRole::Owner)
        ->and($platformOwner->isPlatformOwner())->toBeTrue()
        ->and($platformOwner->belongsToTeam($organization))->toBeFalse()
        ->and(Gate::forUser($platformOwner)->allows('view', $organization))->toBeFalse();
});

test('only the platform owner can create a real organization', function () {
    $platformOwner = User::factory()->platformOwner()->create();
    $currentTeamId = $platformOwner->current_team_id;

    $organization = app(CreateTeam::class)->handle(
        $platformOwner,
        'Empresa autorizada',
    );

    expect($organization->is_personal)->toBeFalse()
        ->and($platformOwner->fresh()->belongsToTeam($organization))->toBeFalse()
        ->and($platformOwner->fresh()->current_team_id)->toBe($currentTeamId);
});

test('organization admins and collaborators cannot create organizations', function () {
    $this->seed(C89OrganizationSeeder::class);

    $administrator = User::query()->where('email', 'admin@c89.com.mx')->firstOrFail();
    $collaborator = User::query()->where('email', 'colaborador@c89.com.mx')->firstOrFail();

    foreach ([$administrator, $collaborator] as $user) {
        expect(fn () => app(CreateTeam::class)->handle(
            $user,
            'Empresa no autorizada',
        ))->toThrow(AuthorizationException::class);
    }

    expect(Team::query()->where('name', 'Empresa no autorizada')->exists())->toBeFalse();
});

test('the create organization action remains protected when Livewire is manipulated', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::teams.index')
        ->set('name', 'Empresa manipulada')
        ->call('createTeam')
        ->assertForbidden();

    expect(Team::query()->where('name', 'Empresa manipulada')->exists())->toBeFalse();
});

test('personal teams remain available only as a one-time compatibility flow', function () {
    $user = User::query()->create([
        'name' => 'Nuevo usuario',
        'email' => 'nuevo@example.com',
        'password' => 'password',
    ]);

    $personalTeam = app(CreateTeam::class)->handle(
        $user,
        'Equipo personal',
        isPersonal: true,
    );

    expect($personalTeam->is_personal)->toBeTrue()
        ->and($user->fresh()->belongsToTeam($personalTeam))->toBeTrue()
        ->and($user->fresh()->teamRole($personalTeam))->toBe(TeamRole::Owner);

    expect(fn () => app(CreateTeam::class)->handle(
        $user->fresh(),
        'Segundo equipo personal',
        isPersonal: true,
    ))->toThrow(AuthorizationException::class);
});

test('organization admins and collaborators cannot receive the global role through mass assignment', function () {
    $this->seed(C89OrganizationSeeder::class);

    $users = User::query()
        ->whereIn('email', ['admin@c89.com.mx', 'colaborador@c89.com.mx'])
        ->get();

    expect($users)->toHaveCount(2);

    foreach ($users as $user) {
        $user->update(['platform_role' => PlatformRole::Owner->value]);

        expect($user->refresh()->platform_role)->toBeNull()
            ->and($user->isPlatformOwner())->toBeFalse();
    }
});

test('organization invitations reject the legacy owner role from manipulated input', function () {
    $this->seed(C89OrganizationSeeder::class);

    $team = Team::query()->where('slug', 'c89')->firstOrFail();
    $administrator = User::query()->where('email', 'admin@c89.com.mx')->firstOrFail();

    Livewire::actingAs($administrator)
        ->test('pages::teams.invite-member-modal', ['team' => $team])
        ->set('inviteEmail', 'nuevo-admin@example.com')
        ->set('inviteRole', TeamRole::Owner->value)
        ->call('createInvitation')
        ->assertHasErrors(['inviteRole']);

    $this->assertDatabaseMissing('team_invitations', [
        'team_id' => $team->id,
        'email' => 'nuevo-admin@example.com',
    ]);
});

test('organization member updates reject the legacy owner role from manipulated input', function () {
    $tenantOwner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($tenantOwner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    Livewire::actingAs($tenantOwner)
        ->test('pages::teams.edit', ['team' => $team])
        ->call('updateMember', $member->id, TeamRole::Owner->value)
        ->assertHasErrors(['role']);

    expect($member->fresh()->teamRole($team))->toBe(TeamRole::Member);
});

test('an existing invitation with the legacy owner role cannot be accepted', function () {
    $tenantOwner = User::factory()->create();
    $invitee = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($tenantOwner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::query()->create([
        'team_id' => $team->id,
        'email' => $invitee->email,
        'role' => TeamRole::Owner,
        'invited_by' => $tenantOwner->id,
    ]);

    Livewire::actingAs($invitee)
        ->test('pages::teams.pending-invitations-modal')
        ->call('acceptInvitation', $invitation->code)
        ->assertHasErrors(['invitation']);

    expect($invitee->fresh()->belongsToTeam($team))->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

test('organization creation controls are hidden from regular users', function () {
    $regularUser = User::factory()->create();
    $platformOwner = User::factory()->platformOwner()->create();

    Livewire::actingAs($regularUser)
        ->test('pages::teams.index')
        ->assertDontSeeHtml('data-test="teams-new-team-button"');

    Livewire::actingAs($platformOwner)
        ->test('pages::teams.index')
        ->assertSeeHtml('data-test="teams-new-team-button"');
});

test('only the platform owner can open organization creation modals', function () {
    $regularUser = User::factory()->create();
    $platformOwner = User::factory()->platformOwner()->create();

    Livewire::actingAs($regularUser)
        ->test('pages::teams.index')
        ->call('showCreateTeamModal')
        ->assertForbidden();

    Livewire::actingAs($regularUser)
        ->test('team-switcher')
        ->call('showCreateTeamModal')
        ->assertForbidden();

    Livewire::actingAs($platformOwner)
        ->test('pages::teams.index')
        ->call('showCreateTeamModal')
        ->assertDispatched('modal-show');

    Livewire::actingAs($platformOwner)
        ->test('team-switcher')
        ->call('showCreateTeamModal')
        ->assertDispatched('modal-show');
});

test('the platform owner sees all real organizations without becoming a member', function () {
    $platformOwner = User::factory()->platformOwner()->create();
    $regularUser = User::factory()->create();
    $organization = Team::factory()->create(['name' => 'Empresa visible']);

    expect($platformOwner->belongsToTeam($organization))->toBeFalse();

    Livewire::actingAs($platformOwner)
        ->test('pages::teams.index')
        ->assertSeeHtml('data-test="platform-organizations"')
        ->assertSee('Empresa visible');

    Livewire::actingAs($regularUser)
        ->test('pages::teams.index')
        ->assertDontSeeHtml('data-test="platform-organizations"')
        ->assertDontSee('Empresa visible');
});

test('the platform owner can update a real organization globally without membership', function () {
    $platformOwner = User::factory()->platformOwner()->create();
    $organization = Team::factory()->create(['name' => 'Nombre anterior']);

    $updatedOrganization = app(UpdateOrganization::class)->handle(
        $platformOwner,
        $organization,
        'Nombre actualizado',
    );

    expect($updatedOrganization->name)->toBe('Nombre actualizado')
        ->and($updatedOrganization->slug)->toBe('nombre-actualizado')
        ->and($platformOwner->belongsToTeam($updatedOrganization))->toBeFalse();
});

test('tenant administrators cannot use global organization editing', function () {
    $administrator = User::factory()->create();
    $organization = Team::factory()->create(['name' => 'Empresa protegida']);

    $organization->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    expect(Gate::forUser($administrator)->allows('update', $organization))->toBeTrue()
        ->and(Gate::forUser($administrator)->allows('updateGlobally', $organization))->toBeFalse();

    expect(fn () => app(UpdateOrganization::class)->handle(
        $administrator,
        $organization,
        'Intento manipulado',
    ))->toThrow(AuthorizationException::class);

    expect($organization->fresh()->name)->toBe('Empresa protegida');
});

test('the platform owner can edit an organization through the owner interface', function () {
    $platformOwner = User::factory()->platformOwner()->create();
    $organization = Team::factory()->create(['name' => 'Empresa editable']);

    Livewire::actingAs($platformOwner)
        ->test('pages::teams.index')
        ->call('showEditOrganizationModal', $organization->id)
        ->assertSet('editingOrganizationId', $organization->id)
        ->assertSet('editingOrganizationName', 'Empresa editable')
        ->assertDispatched('modal-show')
        ->set('editingOrganizationName', 'Empresa corregida')
        ->call('updateOrganization')
        ->assertHasNoErrors();

    expect($organization->fresh()->name)->toBe('Empresa corregida')
        ->and($organization->fresh()->slug)->toBe('empresa-corregida');
});

test('a manipulated owner interface cannot edit organizations as a regular user', function () {
    $regularUser = User::factory()->create();
    $organization = Team::factory()->create(['name' => 'Empresa segura']);

    Livewire::actingAs($regularUser)
        ->test('pages::teams.index')
        ->call('showEditOrganizationModal', $organization->id)
        ->assertForbidden();

    expect($organization->fresh()->name)->toBe('Empresa segura');
});

test('the platform owner can soft delete an empty real organization', function () {
    $platformOwner = User::factory()->platformOwner()->create();
    $organization = Team::factory()->create(['name' => 'Empresa vacía']);

    app(DeleteOrganization::class)->handle($platformOwner, $organization);

    $this->assertSoftDeleted('teams', ['id' => $organization->id]);
    expect($platformOwner->belongsToTeam($organization))->toBeFalse();
});

test('the platform owner cannot delete a personal compatibility team globally', function () {
    $platformOwner = User::factory()->platformOwner()->create();
    $personalTeam = $platformOwner->teams()->where('is_personal', true)->firstOrFail();

    expect(fn () => app(DeleteOrganization::class)->handle(
        $platformOwner,
        $personalTeam,
    ))->toThrow(AuthorizationException::class);

    expect($personalTeam->fresh())->not->toBeNull();
});

test('global organization deletion protects memberships courses and invitations', function () {
    $platformOwner = User::factory()->platformOwner()->create();
    $member = User::factory()->create();
    $inviter = User::factory()->create();

    $organizationWithMember = Team::factory()->create();
    $organizationWithMember->members()->attach($member, ['role' => TeamRole::Member->value]);

    $organizationWithCourse = Team::factory()->create();
    Course::factory()->create(['team_id' => $organizationWithCourse->id]);

    $organizationWithInvitation = Team::factory()->create();
    TeamInvitation::factory()->create([
        'team_id' => $organizationWithInvitation->id,
        'invited_by' => $inviter->id,
    ]);

    foreach ([$organizationWithMember, $organizationWithCourse, $organizationWithInvitation] as $organization) {
        expect(fn () => app(DeleteOrganization::class)->handle(
            $platformOwner,
            $organization,
        ))->toThrow(ValidationException::class);

        expect($organization->fresh())->not->toBeNull();
    }
});

test('regular users cannot delete organizations through the global action or interface', function () {
    $regularUser = User::factory()->create();
    $organization = Team::factory()->create(['name' => 'Empresa protegida']);

    expect(fn () => app(DeleteOrganization::class)->handle(
        $regularUser,
        $organization,
    ))->toThrow(AuthorizationException::class);

    Livewire::actingAs($regularUser)
        ->test('pages::teams.index')
        ->call('showDeleteOrganizationModal', $organization->id)
        ->assertForbidden();

    expect($organization->fresh())->not->toBeNull();
});

test('the platform owner can delete an empty organization through the owner interface', function () {
    $platformOwner = User::factory()->platformOwner()->create();
    $organization = Team::factory()->create(['name' => 'Empresa eliminable']);

    Livewire::actingAs($platformOwner)
        ->test('pages::teams.index')
        ->call('showDeleteOrganizationModal', $organization->id)
        ->assertSet('deletingOrganizationId', $organization->id)
        ->assertSet('deletingOrganizationName', 'Empresa eliminable')
        ->assertDispatched('modal-show')
        ->call('deleteOrganization')
        ->assertHasNoErrors();

    $this->assertSoftDeleted('teams', ['id' => $organization->id]);
});

test('the platform owner can invite the first organization administrator without membership', function () {
    Notification::fake();

    $platformOwner = User::factory()->platformOwner()->create();
    $organization = Team::factory()->create();

    $invitation = app(InviteInitialOrganizationAdministrator::class)->handle(
        $platformOwner,
        $organization,
        'ADMIN@EXAMPLE.COM',
    );

    expect($invitation->email)->toBe('admin@example.com')
        ->and($invitation->role)->toBe(TeamRole::Admin)
        ->and($invitation->invited_by)->toBe($platformOwner->id)
        ->and($invitation->expires_at)->not->toBeNull()
        ->and($platformOwner->belongsToTeam($organization))->toBeFalse();
});

test('an organization with an administrator or pending admin invitation rejects another initial assignment', function () {
    $platformOwner = User::factory()->platformOwner()->create();
    $administrator = User::factory()->create();

    $organizationWithAdministrator = Team::factory()->create();
    $organizationWithAdministrator->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    $organizationWithPendingInvitation = Team::factory()->create();
    TeamInvitation::factory()->create([
        'team_id' => $organizationWithPendingInvitation->id,
        'role' => TeamRole::Admin,
        'invited_by' => $platformOwner->id,
        'expires_at' => now()->addDay(),
    ]);

    foreach ([$organizationWithAdministrator, $organizationWithPendingInvitation] as $organization) {
        expect(fn () => app(InviteInitialOrganizationAdministrator::class)->handle(
            $platformOwner,
            $organization,
            'otro-admin@example.com',
        ))->toThrow(ValidationException::class);
    }
});

test('regular users cannot assign the initial organization administrator', function () {
    $regularUser = User::factory()->create();
    $organization = Team::factory()->create();

    expect(fn () => app(InviteInitialOrganizationAdministrator::class)->handle(
        $regularUser,
        $organization,
        'admin@example.com',
    ))->toThrow(AuthorizationException::class);

    Livewire::actingAs($regularUser)
        ->test('pages::teams.index')
        ->call('showAssignAdministratorModal', $organization->id)
        ->assertForbidden();
});

test('the owner interface sends an initial administrator invitation', function () {
    Notification::fake();

    $platformOwner = User::factory()->platformOwner()->create();
    $organization = Team::factory()->create(['name' => 'Empresa nueva']);

    Livewire::actingAs($platformOwner)
        ->test('pages::teams.index')
        ->call('showAssignAdministratorModal', $organization->id)
        ->assertSet('administratorOrganizationId', $organization->id)
        ->assertSet('administratorOrganizationName', 'Empresa nueva')
        ->assertDispatched('modal-show')
        ->set('administratorEmail', 'nuevo-admin@example.com')
        ->call('inviteInitialAdministrator')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('team_invitations', [
        'team_id' => $organization->id,
        'email' => 'nuevo-admin@example.com',
        'role' => TeamRole::Admin->value,
        'invited_by' => $platformOwner->id,
    ]);
});

test('accepting the initial administrator invitation creates only a tenant admin membership', function () {
    Notification::fake();

    $platformOwner = User::factory()->platformOwner()->create();
    $administrator = User::factory()->create(['email' => 'admin@example.com']);
    $organization = Team::factory()->create();

    $invitation = app(InviteInitialOrganizationAdministrator::class)->handle(
        $platformOwner,
        $organization,
        $administrator->email,
    );

    Livewire::actingAs($administrator)
        ->test('pages::teams.pending-invitations-modal')
        ->call('acceptInvitation', $invitation->code)
        ->assertHasNoErrors();

    expect($administrator->fresh()->teamRole($organization))->toBe(TeamRole::Admin)
        ->and($administrator->fresh()->platform_role)->toBeNull()
        ->and($platformOwner->belongsToTeam($organization))->toBeFalse();
});

test('the platform owner can cancel an unaccepted initial administrator invitation', function () {
    $platformOwner = User::factory()->platformOwner()->create();
    $organization = Team::factory()->create();

    $invitation = app(InviteInitialOrganizationAdministrator::class)->handle(
        $platformOwner,
        $organization,
        'admin@example.com',
    );

    app(CancelInitialOrganizationAdministratorInvitation::class)->handle(
        $platformOwner,
        $organization,
        $invitation->code,
    );

    $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
});

test('regular users cannot cancel initial administrator invitations', function () {
    $platformOwner = User::factory()->platformOwner()->create();
    $regularUser = User::factory()->create();
    $organization = Team::factory()->create();

    $invitation = app(InviteInitialOrganizationAdministrator::class)->handle(
        $platformOwner,
        $organization,
        'admin@example.com',
    );

    expect(fn () => app(CancelInitialOrganizationAdministratorInvitation::class)->handle(
        $regularUser,
        $organization,
        $invitation->code,
    ))->toThrow(AuthorizationException::class);

    Livewire::actingAs($regularUser)
        ->test('pages::teams.index')
        ->call('showCancelAdministratorInvitationModal', $organization->id)
        ->assertForbidden();

    $this->assertDatabaseHas('team_invitations', ['id' => $invitation->id]);
});

test('an initial administrator invitation cannot be canceled after an administrator exists', function () {
    $platformOwner = User::factory()->platformOwner()->create();
    $administrator = User::factory()->create();
    $organization = Team::factory()->create();

    $invitation = app(InviteInitialOrganizationAdministrator::class)->handle(
        $platformOwner,
        $organization,
        'pending-admin@example.com',
    );

    $organization->members()->attach($administrator, ['role' => TeamRole::Admin->value]);

    expect(fn () => app(CancelInitialOrganizationAdministratorInvitation::class)->handle(
        $platformOwner,
        $organization,
        $invitation->code,
    ))->toThrow(ValidationException::class);

    $this->assertDatabaseHas('team_invitations', ['id' => $invitation->id]);
});

test('the owner interface can cancel the pending initial administrator invitation', function () {
    $platformOwner = User::factory()->platformOwner()->create();
    $organization = Team::factory()->create(['name' => 'Empresa pendiente']);

    $invitation = app(InviteInitialOrganizationAdministrator::class)->handle(
        $platformOwner,
        $organization,
        'admin-pendiente@example.com',
    );

    Livewire::actingAs($platformOwner)
        ->test('pages::teams.index')
        ->call('showCancelAdministratorInvitationModal', $organization->id)
        ->assertSet('cancelingAdministratorInvitationOrganizationId', $organization->id)
        ->assertSet('cancelingAdministratorInvitationCode', $invitation->code)
        ->assertSet('cancelingAdministratorInvitationEmail', 'admin-pendiente@example.com')
        ->assertDispatched('modal-show')
        ->call('cancelInitialAdministratorInvitation')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
});
