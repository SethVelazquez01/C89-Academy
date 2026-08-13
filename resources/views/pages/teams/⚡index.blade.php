<?php

use App\Actions\Teams\CancelInitialOrganizationAdministratorInvitation;
use App\Actions\Teams\CreateTeam;
use App\Actions\Teams\DeleteOrganization;
use App\Actions\Teams\InviteInitialOrganizationAdministrator;
use App\Actions\Teams\ProvisionOrganizationUser;
use App\Actions\Teams\ReviewSeatIncreaseRequest;
use App\Actions\Teams\UpdateOrganization;
use App\Data\UserTeam;
use App\Enums\OrganizationSeatType;
use App\Enums\SeatIncreaseRequestStatus;
use App\Enums\TeamRole;
use App\Models\SeatIncreaseRequest;
use App\Models\Team;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Rules\TeamName;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Organizaciones')] class extends Component {
    public string $name = '';

    public string $administratorSeats = '1';

    public string $collaboratorSeats = '1';

    public ?int $editingOrganizationId = null;

    public string $editingOrganizationName = '';

    public ?int $deletingOrganizationId = null;

    public string $deletingOrganizationName = '';

    public ?int $administratorOrganizationId = null;

    public string $administratorOrganizationName = '';

    public string $administratorEmail = '';

    public ?int $cancelingAdministratorInvitationOrganizationId = null;

    public string $cancelingAdministratorInvitationOrganizationName = '';

    public string $cancelingAdministratorInvitationCode = '';

    public string $cancelingAdministratorInvitationEmail = '';

    public ?int $reviewingSeatIncreaseRequestId = null;

    public array $reviewingSeatIncreaseRequestData = [];

    public string $seatIncreaseReviewNotes = '';

    public ?int $viewingOrganizationId = null;

    public string $viewingOrganizationName = '';

    public array $viewingOrganizationMembers = [];

    public array $viewingOrganizationInvitations = [];

    public array $viewingOrganizationSeats = [];

    public string $ownerAdministratorName = '';

    public string $ownerAdministratorEmail = '';

    public string $ownerAdministratorPassword = '';

    public string $ownerAdministratorPasswordConfirmation = '';

    public function createTeam(CreateTeam $createTeam): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', new TeamName],
            'administratorSeats' => ['required', 'integer', 'min:1', 'max:100000'],
            'collaboratorSeats' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        $team = $createTeam->handle(
            Auth::user(),
            $validated['name'],
            includedAdministratorSeats: (int) $validated['administratorSeats'],
            includedCollaboratorSeats: (int) $validated['collaboratorSeats'],
        );

        $this->dispatch('close-modal', name: 'create-team');

        $this->reset('name', 'administratorSeats', 'collaboratorSeats');

        Flux::toast(variant: 'success', text: 'Empresa creada correctamente.');

        $this->redirectRoute('teams.index', navigate: true);
    }

    public function showCreateTeamModal(): void
    {
        Gate::authorize('create', Team::class);

        Flux::modal('create-team')->show();
    }

    public function showEditOrganizationModal(int $organizationId): void
    {
        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($organizationId);

        Gate::authorize('updateGlobally', $organization);

        $this->resetValidation();
        $this->editingOrganizationId = $organization->id;
        $this->editingOrganizationName = $organization->name;

        Flux::modal('edit-platform-organization')->show();
    }

    public function updateOrganization(UpdateOrganization $updateOrganization): void
    {
        $validated = $this->validate([
            'editingOrganizationName' => ['required', 'string', 'max:255', new TeamName],
        ]);

        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($this->editingOrganizationId);

        $updateOrganization->handle(
            Auth::user(),
            $organization,
            $validated['editingOrganizationName'],
        );

        Flux::modal('edit-platform-organization')->close();

        $this->reset('editingOrganizationId', 'editingOrganizationName');

        Flux::toast(variant: 'success', text: __('Organization updated.'));

        $this->redirectRoute('teams.index', navigate: true);
    }

    public function showDeleteOrganizationModal(int $organizationId): void
    {
        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($organizationId);

        Gate::authorize('deleteGlobally', $organization);

        $this->resetValidation();
        $this->deletingOrganizationId = $organization->id;
        $this->deletingOrganizationName = $organization->name;

        Flux::modal('delete-platform-organization')->show();
    }

    public function deleteOrganization(DeleteOrganization $deleteOrganization): void
    {
        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($this->deletingOrganizationId);

        $deleteOrganization->handle(Auth::user(), $organization);

        Flux::modal('delete-platform-organization')->close();

        $this->reset('deletingOrganizationId', 'deletingOrganizationName');

        Flux::toast(variant: 'success', text: __('Organization deleted.'));

        $this->redirectRoute('teams.index', navigate: true);
    }

    public function showAssignAdministratorModal(int $organizationId): void
    {
        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($organizationId);

        Gate::authorize('assignAdministratorGlobally', $organization);

        $this->resetValidation();
        $this->administratorOrganizationId = $organization->id;
        $this->administratorOrganizationName = $organization->name;
        $this->administratorEmail = '';

        Flux::modal('assign-platform-organization-administrator')->show();
    }

    public function inviteInitialAdministrator(InviteInitialOrganizationAdministrator $inviteAdministrator): void
    {
        $validated = $this->validate([
            'administratorEmail' => ['required', 'string', 'email', 'max:255'],
        ]);

        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($this->administratorOrganizationId);

        $invitation = $inviteAdministrator->handle(
            Auth::user(),
            $organization,
            $validated['administratorEmail'],
        );

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        Flux::modal('assign-platform-organization-administrator')->close();

        $this->reset('administratorOrganizationId', 'administratorOrganizationName', 'administratorEmail');

        Flux::toast(variant: 'success', text: __('Administrator invitation sent.'));

        $this->redirectRoute('teams.index', navigate: true);
    }

    public function showCancelAdministratorInvitationModal(int $organizationId): void
    {
        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($organizationId);

        Gate::authorize('cancelAdministratorInvitationGlobally', $organization);

        $invitation = $organization->invitations()
            ->where('role', TeamRole::Admin->value)
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->latest()
            ->firstOrFail();

        $this->resetValidation();
        $this->cancelingAdministratorInvitationOrganizationId = $organization->id;
        $this->cancelingAdministratorInvitationOrganizationName = $organization->name;
        $this->cancelingAdministratorInvitationCode = $invitation->code;
        $this->cancelingAdministratorInvitationEmail = $invitation->email;

        Flux::modal('cancel-platform-administrator-invitation')->show();
    }

    public function cancelInitialAdministratorInvitation(CancelInitialOrganizationAdministratorInvitation $cancelInvitation): void
    {
        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($this->cancelingAdministratorInvitationOrganizationId);

        $cancelInvitation->handle(
            Auth::user(),
            $organization,
            $this->cancelingAdministratorInvitationCode,
        );

        Flux::modal('cancel-platform-administrator-invitation')->close();

        $this->reset(
            'cancelingAdministratorInvitationOrganizationId',
            'cancelingAdministratorInvitationOrganizationName',
            'cancelingAdministratorInvitationCode',
            'cancelingAdministratorInvitationEmail',
        );

        Flux::toast(variant: 'success', text: __('Administrator invitation canceled.'));

        $this->redirectRoute('teams.index', navigate: true);
    }

    public function showSeatIncreaseReviewModal(int $requestId): void
    {
        $request = SeatIncreaseRequest::query()
            ->with(['team', 'requester'])
            ->where('status', SeatIncreaseRequestStatus::Pending->value)
            ->findOrFail($requestId);

        Gate::authorize('reviewSeatIncreaseGlobally', $request->team);

        $this->resetValidation();
        $this->reviewingSeatIncreaseRequestId = $request->id;
        $this->seatIncreaseReviewNotes = '';
        $this->reviewingSeatIncreaseRequestData = [
            'organization' => $request->team->name,
            'requester' => $request->requester?->name ?? 'Usuario eliminado',
            'administrator_seats' => $request->requested_administrator_seats,
            'collaborator_seats' => $request->requested_collaborator_seats,
            'justification' => $request->justification,
            'created_at' => $request->created_at?->format('d/m/Y H:i'),
            'current_administrator_capacity' => $request->team->seatCapacity(OrganizationSeatType::Administrator),
            'current_collaborator_capacity' => $request->team->seatCapacity(OrganizationSeatType::Collaborator),
        ];

        Flux::modal('review-seat-increase-request')->show();
    }

    public function reviewSeatIncreaseRequest(
        string $decision,
        ReviewSeatIncreaseRequest $reviewSeatIncreaseRequest,
    ): void {
        $validated = $this->validate([
            'seatIncreaseReviewNotes' => ['nullable', 'string', 'max:1000'],
        ], [
            'seatIncreaseReviewNotes.max' => 'Las notas de revisión no pueden superar 1000 caracteres.',
        ]);

        if ($decision === SeatIncreaseRequestStatus::Rejected->value
            && blank($validated['seatIncreaseReviewNotes'])) {
            throw ValidationException::withMessages([
                'seatIncreaseReviewNotes' => ['Debes indicar el motivo del rechazo.'],
            ]);
        }

        $request = SeatIncreaseRequest::query()->findOrFail($this->reviewingSeatIncreaseRequestId);

        $reviewedRequest = $reviewSeatIncreaseRequest->handle(
            Auth::user(),
            $request,
            $decision,
            $validated['seatIncreaseReviewNotes'] ?: null,
        );

        Flux::modal('review-seat-increase-request')->close();

        $this->reset(
            'reviewingSeatIncreaseRequestId',
            'reviewingSeatIncreaseRequestData',
            'seatIncreaseReviewNotes',
        );
        unset($this->pendingSeatIncreaseRequests);

        Flux::toast(
            variant: 'success',
            text: $reviewedRequest->status === SeatIncreaseRequestStatus::Approved
                ? 'Solicitud de cupos aprobada.'
                : 'Solicitud de cupos rechazada.',
        );
    }

    public function showOrganizationMembersModal(int $organizationId): void
    {
        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($organizationId);

        Gate::authorize('viewMembersGlobally', $organization);

        $this->resetValidation();
        $this->viewingOrganizationId = $organization->id;
        $this->viewingOrganizationName = $organization->name;
        $this->ownerAdministratorName = '';
        $this->ownerAdministratorEmail = '';
        $this->ownerAdministratorPassword = '';
        $this->ownerAdministratorPasswordConfirmation = '';
        $this->populateViewingOrganization($organization);

        Flux::modal('view-platform-organization-members')->show();
    }

    public function createOrganizationAdministrator(
        ProvisionOrganizationUser $provisionOrganizationUser,
    ): void {
        $validated = $this->validate([
            'ownerAdministratorName' => ['required', 'string', 'max:255'],
            'ownerAdministratorEmail' => ['required', 'string', 'email', 'max:255'],
            'ownerAdministratorPassword' => ['required', 'string', 'min:8', 'confirmed:ownerAdministratorPasswordConfirmation'],
        ], [
            'ownerAdministratorName.required' => 'El nombre es obligatorio.',
            'ownerAdministratorName.max' => 'El nombre no puede superar 255 caracteres.',
            'ownerAdministratorEmail.required' => 'El correo electrónico es obligatorio.',
            'ownerAdministratorEmail.email' => 'El correo electrónico no tiene un formato válido.',
            'ownerAdministratorPassword.required' => 'La contraseña es obligatoria.',
            'ownerAdministratorPassword.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'ownerAdministratorPassword.confirmed' => 'La confirmación de contraseña no coincide.',
        ]);

        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($this->viewingOrganizationId);

        try {
            $provisionOrganizationUser->handle(
                Auth::user(),
                $organization,
                $validated['ownerAdministratorName'],
                $validated['ownerAdministratorEmail'],
                TeamRole::Admin->value,
                $validated['ownerAdministratorPassword'],
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'ownerAdministratorEmail' => collect($exception->errors())->flatten()->all(),
            ]);
        }

        $this->reset(
            'ownerAdministratorName',
            'ownerAdministratorEmail',
            'ownerAdministratorPassword',
            'ownerAdministratorPasswordConfirmation',
        );
        $this->populateViewingOrganization($organization->fresh());
        unset($this->platformOrganizations);

        Flux::toast(
            variant: 'success',
            text: 'Administrador creado y listo para iniciar sesión.',
        );
    }

    private function populateViewingOrganization(Team $organization): void
    {
        $this->viewingOrganizationMembers = $organization->members()
            ->orderByRaw('LOWER(name)')
            ->get()
            ->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'initials' => $member->initials(),
                'role' => $member->pivot->role->value,
                'role_label' => $member->pivot->role->label(),
                'status' => $member->pivot->status->value,
                'status_label' => $member->pivot->status->label(),
            ])
            ->toArray();

        $this->viewingOrganizationInvitations = $organization->invitations()
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn ($invitation) => [
                'email' => $invitation->email,
                'role_label' => $invitation->role->label(),
                'expires_at' => $invitation->expires_at?->format('d/m/Y H:i'),
            ])
            ->toArray();

        $this->viewingOrganizationSeats = [
            'administrators' => [
                'occupied' => $organization->occupiedSeats(OrganizationSeatType::Administrator),
                'capacity' => $organization->seatCapacity(OrganizationSeatType::Administrator),
            ],
            'collaborators' => [
                'occupied' => $organization->occupiedSeats(OrganizationSeatType::Collaborator),
                'capacity' => $organization->seatCapacity(OrganizationSeatType::Collaborator),
            ],
        ];
    }

    public function leaveTeam(int $teamId): void
    {
        $team = Team::findOrFail($teamId);
        $user = Auth::user();

        Gate::authorize('leave', $team);

        $fallbackTeam = $user->isCurrentTeam($team)
            ? $user->fallbackTeam($team)
            : null;

        $team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($fallbackTeam) {
            $user->switchTeam($fallbackTeam);
        }

        $this->dispatch('close-modal', name: "leave-team-{$teamId}");

        Flux::toast(variant: 'success', text: __('You left the team ":name"', ['name' => $team->name]));

        $this->redirectRoute('teams.index', navigate: true);
    }

    /**
     * @return Collection<int, UserTeam>
     */
    #[Computed]
    public function teams(): Collection
    {
        return Auth::user()->toUserTeams(includeCurrent: true);
    }

    /**
     * Get every real organization for the global platform owner.
     *
     * @return Collection<int, Team>
     */
    #[Computed]
    public function platformOrganizations(): Collection
    {
        Gate::authorize('create', Team::class);

        return Team::query()
            ->where('is_personal', false)
            ->withCount([
                'members',
                'members as administrators_count' => fn ($query) => $query
                    ->whereIn('team_members.role', [TeamRole::Owner->value, TeamRole::Admin->value]),
                'invitations as pending_administrator_invitations_count' => fn ($query) => $query
                    ->where('team_invitations.role', TeamRole::Admin->value)
                    ->whereNull('accepted_at')
                    ->where(fn ($pendingQuery) => $pendingQuery
                        ->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now())),
            ])
            ->orderByRaw('LOWER(name)')
            ->get();
    }

    /** @return Collection<int, SeatIncreaseRequest> */
    #[Computed]
    public function pendingSeatIncreaseRequests(): Collection
    {
        Gate::authorize('create', Team::class);

        return SeatIncreaseRequest::query()
            ->with(['team', 'requester'])
            ->where('status', SeatIncreaseRequestStatus::Pending->value)
            ->oldest()
            ->get();
    }

    /** @return Collection<int, SeatIncreaseRequest> */
    #[Computed]
    public function recentSeatIncreaseReviews(): Collection
    {
        Gate::authorize('create', Team::class);

        return SeatIncreaseRequest::query()
            ->with(['team', 'requester', 'reviewer'])
            ->whereIn('status', [
                SeatIncreaseRequestStatus::Approved->value,
                SeatIncreaseRequestStatus::Rejected->value,
            ])
            ->latest('reviewed_at')
            ->limit(10)
            ->get();
    }
}; ?>

<section class="w-full">
    @if (Auth::user()->isPlatformOwner())
        <div class="mb-6 flex flex-col gap-4 border-b border-zinc-200 pb-6 dark:border-zinc-700 md:flex-row md:items-end md:justify-between">
            <div>
                <flux:badge color="blue">Administración global</flux:badge>
                <flux:heading size="xl" level="1" class="mt-3">Organizaciones</flux:heading>
                <flux:subheading size="lg" class="mt-1">Gestiona empresas, administradores, miembros y solicitudes de cupos desde un solo lugar.</flux:subheading>
            </div>

            <flux:button :href="route('platform.dashboard')" variant="ghost" icon="arrow-left" wire:navigate>
                Volver al resumen
            </flux:button>
        </div>
    @else
        @include('partials.settings-heading')
    @endif

    <flux:heading class="sr-only">{{ __('Teams') }}</flux:heading>

    <x-pages::settings.layout
        :heading="__('Teams')"
        :subheading="__('Manage your teams and team memberships')"
        :full-width="Auth::user()->isPlatformOwner()"
    >
        @if (Auth::user()->isPlatformOwner())
            <div class="flex items-center justify-end">
                <flux:button
                    variant="primary"
                    icon="plus"
                    wire:click="showCreateTeamModal"
                    data-test="teams-new-team-button"
                >
                    Nueva empresa
                </flux:button>
            </div>

            <div class="mt-6 space-y-3" data-test="platform-organizations">
                <div>
                    <flux:heading size="lg">Empresas registradas</flux:heading>
                    <flux:text>Organizaciones gestionadas globalmente sin convertir al Platform Owner en miembro.</flux:text>
                </div>

                @forelse ($this->platformOrganizations as $organization)
                    <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="platform-organization-row">
                        <div>
                            <div class="font-medium">{{ $organization->name }}</div>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $organization->slug }}</flux:text>
                        </div>

                        <div class="flex items-center gap-2">
                            <flux:badge color="zinc">
                                {{ (int) $organization->getAttribute('members_count') }} {{ (int) $organization->getAttribute('members_count') === 1 ? 'miembro' : 'miembros' }}
                            </flux:badge>

                            @if ((int) $organization->getAttribute('administrators_count') > 0)
                                <flux:badge color="green">
                                    {{ (int) $organization->getAttribute('administrators_count') }} {{ (int) $organization->getAttribute('administrators_count') === 1 ? 'administrador' : 'administradores' }}
                                </flux:badge>
                            @elseif ((int) $organization->getAttribute('pending_administrator_invitations_count') > 0)
                                <flux:badge color="amber">Administrador pendiente</flux:badge>

                                <flux:tooltip content="Cancelar invitación de administrador">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="x-mark"
                                        wire:click="showCancelAdministratorInvitationModal({{ $organization->id }})"
                                        data-test="platform-organization-cancel-administrator-invitation"
                                    />
                                </flux:tooltip>
                            @else
                                <flux:tooltip content="Asignar primer administrador">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="user-plus"
                                        wire:click="showAssignAdministratorModal({{ $organization->id }})"
                                        data-test="platform-organization-assign-administrator"
                                    />
                                </flux:tooltip>
                            @endif

                            <flux:tooltip content="Ver miembros">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="users"
                                    wire:click="showOrganizationMembersModal({{ $organization->id }})"
                                    data-test="platform-organization-view-members"
                                />
                            </flux:tooltip>

                            <flux:tooltip content="Editar empresa">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="pencil"
                                    wire:click="showEditOrganizationModal({{ $organization->id }})"
                                    data-test="platform-organization-edit"
                                />
                            </flux:tooltip>

                            <flux:tooltip content="Eliminar empresa">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    wire:click="showDeleteOrganizationModal({{ $organization->id }})"
                                    class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                    data-test="platform-organization-delete"
                                />
                            </flux:tooltip>
                        </div>
                    </div>
                @empty
                    <flux:text class="rounded-lg border border-dashed border-zinc-300 py-8 text-center text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        Todavía no hay empresas registradas.
                    </flux:text>
                @endforelse
            </div>

            <div class="mt-8 space-y-3" data-test="pending-seat-increase-requests">
                <div>
                    <flux:heading size="lg">Solicitudes de cupos</flux:heading>
                    <flux:text>Revisa las solicitudes pendientes de las organizaciones.</flux:text>
                </div>

                @forelse ($this->pendingSeatIncreaseRequests as $request)
                    <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="seat-increase-request-row">
                        <div>
                            <div class="font-medium">{{ $request->team->name }}</div>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                Solicitada por {{ $request->requester?->name ?? 'Usuario eliminado' }} · {{ $request->created_at?->format('d/m/Y H:i') }}
                            </flux:text>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <flux:badge color="zinc">Administradores: +{{ $request->requested_administrator_seats }}</flux:badge>
                                <flux:badge color="zinc">Colaboradores: +{{ $request->requested_collaborator_seats }}</flux:badge>
                                <flux:badge color="amber">Pendiente</flux:badge>
                            </div>
                        </div>

                        <flux:button
                            variant="outline"
                            wire:click="showSeatIncreaseReviewModal({{ $request->id }})"
                            data-test="review-seat-increase-request-button"
                        >
                            Revisar
                        </flux:button>
                    </div>
                @empty
                    <flux:text class="rounded-lg border border-dashed border-zinc-300 py-8 text-center text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        No hay solicitudes de cupos pendientes.
                    </flux:text>
                @endforelse
            </div>

            @if ($this->recentSeatIncreaseReviews->isNotEmpty())
                <div class="mt-8 space-y-3" data-test="recent-seat-increase-reviews">
                    <div>
                        <flux:heading size="lg">Historial reciente de cupos</flux:heading>
                        <flux:text>Últimas solicitudes aprobadas o rechazadas.</flux:text>
                    </div>

                    @foreach ($this->recentSeatIncreaseReviews as $reviewedRequest)
                        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="reviewed-seat-increase-request-row">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="font-medium">{{ $reviewedRequest->team->name }}</div>
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                        Solicitada por {{ $reviewedRequest->requester?->name ?? 'Usuario eliminado' }} ·
                                        Revisada por {{ $reviewedRequest->reviewer?->name ?? 'Usuario eliminado' }} el {{ $reviewedRequest->reviewed_at?->format('d/m/Y H:i') }}
                                    </flux:text>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <flux:badge color="zinc">Administradores: +{{ $reviewedRequest->requested_administrator_seats }}</flux:badge>
                                        <flux:badge color="zinc">Colaboradores: +{{ $reviewedRequest->requested_collaborator_seats }}</flux:badge>
                                    </div>
                                    @if ($reviewedRequest->review_notes)
                                        <flux:text class="mt-2 text-sm">{{ $reviewedRequest->review_notes }}</flux:text>
                                    @endif
                                </div>

                                <flux:badge :color="$reviewedRequest->status === SeatIncreaseRequestStatus::Approved ? 'green' : 'red'">
                                    {{ $reviewedRequest->status->label() }}
                                </flux:badge>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @else
        <div class="mt-6 space-y-3" data-test="user-team-memberships">
            @forelse ($this->teams as $team)
                <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="team-row">
                    <div class="flex items-center gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-medium">{{ $team->name }}</span>
                                @if ($team->isPersonal)
                                    <flux:badge color="zinc">{{ __('Personal') }}</flux:badge>
                                @endif
                            </div>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $team->roleLabel }}</flux:text>
                        </div>
                    </div>

                    <div class="flex items-center gap-1">
                        @if (! $team->isPersonal && $team->role !== 'owner')
                            <flux:modal.trigger :name="'leave-team-'.$team->id">
                                <flux:tooltip :content="__('Leave team')">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="arrow-right-start-on-rectangle"
                                        x-data=""
                                        x-on:click.prevent="$dispatch('open-modal', 'leave-team-{{ $team->id }}')"
                                        data-test="team-leave-button"
                                    />
                                </flux:tooltip>
                            </flux:modal.trigger>
                        @endif

                        <flux:tooltip :content="$team->role === 'member' ? __('View team') : __('Edit team')">
                            <flux:button
                                variant="ghost"
                                size="sm"
                                :icon="$team->role === 'member' ? 'eye' : 'pencil'"
                                :href="route('teams.edit', $team->slug)"
                                wire:navigate
                                :data-test="$team->role === 'member' ? 'team-view-button' : 'team-edit-button'"
                            />
                        </flux:tooltip>
                    </div>
                </div>

                @if (! $team->isPersonal && $team->role !== 'owner')
                    <flux:modal :name="'leave-team-'.$team->id" focusable class="max-w-lg">
                        <form wire:submit="leaveTeam({{ $team->id }})" class="space-y-6">
                            <div>
                                <flux:heading size="lg">{{ __('Leave team') }}</flux:heading>
                                <flux:subheading>
                                    {{ __('Are you sure you want to leave :name?', ['name' => $team->name]) }}
                                </flux:subheading>
                            </div>

                            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                                <flux:modal.close>
                                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                                </flux:modal.close>

                                <flux:button variant="danger" type="submit" data-test="leave-team-confirm">
                                    {{ __('Leave team') }}
                                </flux:button>
                            </div>
                        </form>
                    </flux:modal>
                @endif
            @empty
                <flux:text class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                    {{ __('You don\'t belong to any teams yet.') }}
                </flux:text>
            @endforelse
        </div>
        @endif
    </x-pages::settings.layout>

    @if (Auth::user()->isPlatformOwner())
        <flux:modal name="create-team" :show="$errors->hasAny(['name', 'administratorSeats', 'collaboratorSeats'])" focusable class="max-w-2xl">
            <form wire:submit="createTeam" class="space-y-6">
                <div>
                    <flux:heading size="lg">Registrar nueva empresa</flux:heading>
                    <flux:subheading>Asigna un nombre para comenzar a administrar la organización.</flux:subheading>
                </div>

                <flux:input wire:model="name" label="Nombre de la empresa" type="text" required autofocus data-test="create-team-name" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="administratorSeats" type="number" min="1" max="100000" label="Cupos de administradores" required data-test="create-team-administrator-seats" />
                    <flux:input wire:model="collaboratorSeats" type="number" min="0" max="100000" label="Cupos de colaboradores" required data-test="create-team-collaborator-seats" />
                </div>

                <flux:text class="text-sm">Estos cupos forman el paquete inicial de la empresa.</flux:text>

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button variant="primary" type="submit" data-test="create-team-submit">
                        Crear empresa
                    </flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="edit-platform-organization" :show="$errors->has('editingOrganizationName')" focusable class="max-w-lg">
            <form wire:submit="updateOrganization" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Edit organization') }}</flux:heading>
                    <flux:subheading>{{ __('Update the organization name. Its slug will be regenerated automatically.') }}</flux:subheading>
                </div>

                <flux:input
                    wire:model="editingOrganizationName"
                    :label="__('Organization name')"
                    type="text"
                    required
                    autofocus
                    data-test="edit-organization-name"
                />

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button variant="primary" type="submit" data-test="edit-organization-submit">
                        {{ __('Save changes') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="delete-platform-organization" :show="$errors->has('organization')" focusable class="max-w-lg">
            <form wire:submit="deleteOrganization" class="space-y-6">
                <div>
                    <flux:heading size="lg">Eliminar organización</flux:heading>
                    <flux:subheading>
                        ¿Eliminar {{ $deletingOrganizationName }}? Solo se permiten organizaciones sin actividad vigente.
                    </flux:subheading>
                </div>

                @error('organization')
                    <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">Cancelar</flux:button>
                    </flux:modal.close>

                    <flux:button variant="danger" type="submit" data-test="delete-organization-confirm">
                        Eliminar organización
                    </flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="assign-platform-organization-administrator" :show="$errors->has('administratorEmail')" focusable class="max-w-lg">
            <form wire:submit="inviteInitialAdministrator" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Assign first administrator') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Invite an administrator to :name. The person must accept the invitation.', ['name' => $administratorOrganizationName]) }}
                    </flux:subheading>
                </div>

                <flux:input
                    wire:model="administratorEmail"
                    :label="__('Administrator email')"
                    type="email"
                    required
                    autofocus
                    data-test="administrator-email"
                />

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button variant="primary" type="submit" data-test="administrator-invitation-submit">
                        {{ __('Send administrator invitation') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="cancel-platform-administrator-invitation" :show="$errors->has('administratorInvitation')" focusable class="max-w-lg">
            <form wire:submit="cancelInitialAdministratorInvitation" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Cancel administrator invitation') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Cancel the invitation for :email to administer :name?', [
                            'email' => $cancelingAdministratorInvitationEmail,
                            'name' => $cancelingAdministratorInvitationOrganizationName,
                        ]) }}
                    </flux:subheading>
                </div>

                @error('administratorInvitation')
                    <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Keep invitation') }}</flux:button>
                    </flux:modal.close>

                    <flux:button variant="danger" type="submit" data-test="cancel-administrator-invitation-confirm">
                        {{ __('Cancel invitation') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="review-seat-increase-request" :show="$errors->hasAny(['seatIncreaseReviewNotes', 'seatIncreaseRequest'])" focusable class="max-w-xl">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Revisar solicitud de cupos</flux:heading>
                    <flux:subheading>
                        {{ $reviewingSeatIncreaseRequestData['organization'] ?? '' }} · Solicitada por {{ $reviewingSeatIncreaseRequestData['requester'] ?? '' }}
                    </flux:subheading>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <flux:text class="text-sm text-zinc-500">Administradores</flux:text>
                        <div class="font-medium">
                            {{ $reviewingSeatIncreaseRequestData['current_administrator_capacity'] ?? 0 }} actuales
                            + {{ $reviewingSeatIncreaseRequestData['administrator_seats'] ?? 0 }} solicitados
                        </div>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <flux:text class="text-sm text-zinc-500">Colaboradores</flux:text>
                        <div class="font-medium">
                            {{ $reviewingSeatIncreaseRequestData['current_collaborator_capacity'] ?? 0 }} actuales
                            + {{ $reviewingSeatIncreaseRequestData['collaborator_seats'] ?? 0 }} solicitados
                        </div>
                    </div>
                </div>

                <div>
                    <div class="mb-1 text-sm font-medium">Justificación</div>
                    <flux:text>{{ $reviewingSeatIncreaseRequestData['justification'] ?? '' }}</flux:text>
                </div>

                <flux:textarea
                    wire:model="seatIncreaseReviewNotes"
                    label="Notas de revisión"
                    description="Son obligatorias para rechazar y opcionales para aprobar."
                    rows="4"
                    maxlength="1000"
                    data-test="seat-increase-review-notes"
                />

                @error('seatIncreaseRequest')
                    <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">Cancelar</flux:button>
                    </flux:modal.close>
                    <flux:button
                        variant="danger"
                        wire:click="reviewSeatIncreaseRequest('{{ SeatIncreaseRequestStatus::Rejected->value }}')"
                        data-test="reject-seat-increase-request"
                    >
                        Rechazar
                    </flux:button>
                    <flux:button
                        variant="primary"
                        wire:click="reviewSeatIncreaseRequest('{{ SeatIncreaseRequestStatus::Approved->value }}')"
                        data-test="approve-seat-increase-request"
                    >
                        Aprobar
                    </flux:button>
                </div>
            </div>
        </flux:modal>

        <flux:modal name="view-platform-organization-members" :show="$errors->hasAny(['ownerAdministratorName', 'ownerAdministratorEmail', 'ownerAdministratorPassword'])" focusable class="max-w-4xl">
            <div class="space-y-8">
                <div>
                    <flux:heading size="lg">Usuarios de {{ $viewingOrganizationName }}</flux:heading>
                    <flux:subheading>Consulta miembros e invitaciones y crea administradores sin convertirte en miembro de la organización.</flux:subheading>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:text class="text-sm text-zinc-500">Cupos de administradores</flux:text>
                        <div class="text-lg font-medium">
                            {{ $viewingOrganizationSeats['administrators']['occupied'] ?? 0 }} de {{ $viewingOrganizationSeats['administrators']['capacity'] ?? 0 }} ocupados
                        </div>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:text class="text-sm text-zinc-500">Cupos de colaboradores</flux:text>
                        <div class="text-lg font-medium">
                            {{ $viewingOrganizationSeats['collaborators']['occupied'] ?? 0 }} de {{ $viewingOrganizationSeats['collaborators']['capacity'] ?? 0 }} ocupados
                        </div>
                    </div>
                </div>

                <div class="space-y-3" data-test="platform-organization-members-list">
                    <div class="font-medium">Miembros actuales</div>

                    @forelse ($viewingOrganizationMembers as $member)
                        <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" data-test="platform-organization-member-row">
                            <div class="flex items-center gap-3">
                                <flux:avatar :name="$member['name']" :initials="$member['initials']" />
                                <div>
                                    <div class="font-medium">{{ $member['name'] }}</div>
                                    <flux:text class="text-sm text-zinc-500">{{ $member['email'] }}</flux:text>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <flux:badge color="zinc">{{ $member['role_label'] }}</flux:badge>
                                <flux:badge :color="$member['status'] === 'active' ? 'green' : 'amber'">{{ $member['status_label'] }}</flux:badge>
                            </div>
                        </div>
                    @empty
                        <flux:text class="rounded-lg border border-dashed border-zinc-300 p-6 text-center text-zinc-500 dark:border-zinc-700">
                            Esta organización todavía no tiene miembros.
                        </flux:text>
                    @endforelse
                </div>

                @if (count($viewingOrganizationInvitations) > 0)
                    <div class="space-y-3" data-test="platform-organization-pending-invitations">
                        <div class="font-medium">Invitaciones pendientes</div>
                        @foreach ($viewingOrganizationInvitations as $invitation)
                            <div class="flex items-center justify-between rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                <div>
                                    <div>{{ $invitation['email'] }}</div>
                                    <flux:text class="text-sm text-zinc-500">{{ $invitation['role_label'] }}</flux:text>
                                </div>
                                <flux:text class="text-sm text-zinc-500">Vence: {{ $invitation['expires_at'] ?? 'Sin vencimiento' }}</flux:text>
                            </div>
                        @endforeach
                    </div>
                @endif

                <form wire:submit="createOrganizationAdministrator" class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div>
                        <div class="font-medium">Crear administrador</div>
                        <flux:text class="text-sm text-zinc-500">La cuenta quedará lista para iniciar sesión. Una identidad existente conservará su contraseña actual.</flux:text>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="ownerAdministratorName" label="Nombre completo" required data-test="owner-administrator-name" />
                        <flux:input wire:model="ownerAdministratorEmail" type="email" label="Correo electrónico" required data-test="owner-administrator-email" />
                        <flux:input wire:model="ownerAdministratorPassword" type="password" label="Contraseña" viewable required data-test="owner-administrator-password" />
                        <flux:input wire:model="ownerAdministratorPasswordConfirmation" type="password" label="Confirmar contraseña" viewable required data-test="owner-administrator-password-confirmation" />
                    </div>

                    <div class="flex justify-end">
                        <flux:button variant="primary" type="submit" data-test="owner-create-administrator-submit">
                            Crear administrador
                        </flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    @endif
</section>
