<?php

use App\Actions\Teams\ChangeMembershipRole;
use App\Actions\Teams\ChangeMembershipStatus;
use App\Actions\Teams\CreateSeatIncreaseRequest;
use App\Actions\Teams\ProvisionOrganizationUser;
use App\Data\TeamPermissions;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationSeatType;
use App\Enums\SeatIncreaseRequestStatus;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Rules\TeamName;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public string $teamName = '';

    public array $teamData = [];

    public array $members = [];

    public array $invitations = [];

    public array $availableRoles = [];

    public array $seatUsage = [];

    public ?array $pendingSeatIncreaseRequest = null;

    public array $seatIncreaseRequestHistory = [];

    public string $requestedAdministratorSeats = '0';

    public string $requestedCollaboratorSeats = '0';

    public string $seatIncreaseJustification = '';

    public string $newUserName = '';

    public string $newUserEmail = '';

    public string $newUserPassword = '';

    public string $newUserPasswordConfirmation = '';

    public string $newUserRole = 'member';

    public bool $isCurrentTeam = false;

    public function mount(Team $team): void
    {
        $this->teamModel = $team;
        $this->teamName = $team->name;

        $this->populateTeamData();
    }

    public function updateTeam(): void
    {
        Gate::authorize('update', $this->teamModel);

        $validated = $this->validate([
            'teamName' => ['required', 'string', 'max:255', new TeamName],
        ]);

        $team = DB::transaction(function () use ($validated) {
            $team = Team::whereKey($this->teamModel->id)->lockForUpdate()->firstOrFail();

            $team->update(['name' => $validated['teamName']]);

            return $team;
        });

        $this->teamModel = $team;

        $this->populateTeamData();

        Flux::toast(variant: 'success', text: __('Team updated.'));

        $this->redirectRoute('teams.edit', ['team' => $this->teamModel->fresh()->slug], navigate: true);
    }

    public function updateMember(int $userId, string $role, ChangeMembershipRole $changeMembershipRole): void
    {
        $member = User::findOrFail($userId);

        $changeMembershipRole->handle(
            Auth::user(),
            $this->teamModel,
            $member,
            $role,
        );

        $this->populateTeamData();

        Flux::toast(variant: 'success', text: __('Member role updated.'));
    }

    public function suspendMember(int $userId, ChangeMembershipStatus $changeMembershipStatus): void
    {
        $member = User::findOrFail($userId);

        $changeMembershipStatus->handle(
            Auth::user(),
            $this->teamModel,
            $member,
            MembershipStatus::Suspended,
        );

        $this->populateTeamData();

        Flux::toast(variant: 'success', text: __('Member suspended.'));
    }

    public function reactivateMember(int $userId, ChangeMembershipStatus $changeMembershipStatus): void
    {
        $member = User::findOrFail($userId);

        $changeMembershipStatus->handle(
            Auth::user(),
            $this->teamModel,
            $member,
            MembershipStatus::Active,
        );

        $this->populateTeamData();

        Flux::toast(variant: 'success', text: __('Member reactivated.'));
    }

    public function requestAdditionalSeats(CreateSeatIncreaseRequest $createSeatIncreaseRequest): void
    {
        $validated = $this->validate([
            'requestedAdministratorSeats' => ['required', 'integer', 'min:0', 'max:100000'],
            'requestedCollaboratorSeats' => ['required', 'integer', 'min:0', 'max:100000'],
            'seatIncreaseJustification' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'requestedAdministratorSeats.integer' => 'Los cupos de administradores deben ser un número entero.',
            'requestedAdministratorSeats.min' => 'Los cupos de administradores no pueden ser negativos.',
            'requestedCollaboratorSeats.integer' => 'Los cupos de colaboradores deben ser un número entero.',
            'requestedCollaboratorSeats.min' => 'Los cupos de colaboradores no pueden ser negativos.',
            'seatIncreaseJustification.required' => 'La justificación es obligatoria.',
            'seatIncreaseJustification.min' => 'La justificación debe tener al menos 10 caracteres.',
        ]);

        $createSeatIncreaseRequest->handle(
            Auth::user(),
            $this->teamModel,
            (int) $validated['requestedAdministratorSeats'],
            (int) $validated['requestedCollaboratorSeats'],
            $validated['seatIncreaseJustification'],
        );

        Flux::modal('request-additional-seats')->close();

        $this->reset(
            'requestedAdministratorSeats',
            'requestedCollaboratorSeats',
            'seatIncreaseJustification',
        );
        $this->requestedAdministratorSeats = '0';
        $this->requestedCollaboratorSeats = '0';

        $this->populateTeamData();

        Flux::toast(variant: 'success', text: 'Solicitud de cupos enviada.');
    }

    public function createOrganizationUser(ProvisionOrganizationUser $provisionOrganizationUser): void
    {
        Gate::authorize('addMember', $this->teamModel);

        $validated = $this->validate([
            'newUserName' => ['required', 'string', 'max:255'],
            'newUserEmail' => ['required', 'string', 'email', 'max:255'],
            'newUserPassword' => ['required', 'string', 'min:8', 'confirmed:newUserPasswordConfirmation'],
            'newUserRole' => ['required', 'string', Rule::in(TeamRole::assignableValues())],
        ], [
            'newUserName.required' => 'El nombre es obligatorio.',
            'newUserName.max' => 'El nombre no puede superar 255 caracteres.',
            'newUserEmail.required' => 'El correo electrónico es obligatorio.',
            'newUserEmail.email' => 'El correo electrónico no tiene un formato válido.',
            'newUserPassword.required' => 'La contraseña es obligatoria.',
            'newUserPassword.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'newUserPassword.confirmed' => 'La confirmación de contraseña no coincide.',
            'newUserRole.in' => 'El rol seleccionado no es válido.',
        ]);

        try {
            $provisionOrganizationUser->handle(
                Auth::user(),
                $this->teamModel,
                $validated['newUserName'],
                $validated['newUserEmail'],
                $validated['newUserRole'],
                $validated['newUserPassword'],
            );
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            throw ValidationException::withMessages([
                'newUserName' => $errors['name'] ?? [],
                'newUserEmail' => $errors['email'] ?? [],
                'newUserPassword' => $errors['password'] ?? [],
                'newUserRole' => $errors['role'] ?? [],
            ]);
        }

        Flux::modal('create-organization-user')->close();

        $this->reset('newUserName', 'newUserEmail', 'newUserPassword', 'newUserPasswordConfirmation', 'newUserRole');
        $this->newUserRole = TeamRole::Member->value;
        $this->populateTeamData();

        Flux::toast(
            variant: 'success',
            text: 'Usuario creado y listo para iniciar sesión.',
        );
    }

    private function populateTeamData(): void
    {
        $user = Auth::user();

        $team = $this->teamModel->fresh();

        $this->teamData = [
            'id' => $team->id,
            'name' => $team->name,
            'slug' => $team->slug,
            'is_personal' => $team->is_personal,
        ];

        $this->members = $team->members()->get()->map(fn ($member) => [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'avatar' => $member->avatar ?? null,
            'initials' => $member->initials(),
            'role' => $member->pivot->role->value,
            'role_label' => $member->pivot->role->label(),
            'status' => $member->pivot->status->value,
            'status_label' => $member->pivot->status->label(),
            'is_self' => $member->id === $user->id,
        ])->toArray();

        $this->invitations = $team->invitations()
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->get()
            ->map(fn ($invitation) => [
                'code' => $invitation->code,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'role_label' => $invitation->role->label(),
                'created_at' => $invitation->created_at->toISOString(),
            ])->toArray();

        $this->availableRoles = TeamRole::assignable();

        $this->seatUsage = collect(OrganizationSeatType::cases())
            ->mapWithKeys(fn (OrganizationSeatType $seatType) => [
                $seatType->value => [
                    'label' => $seatType->pluralLabel(),
                    'capacity' => $team->seatCapacity($seatType),
                    'occupied' => $team->occupiedSeats($seatType),
                    'available' => $team->availableSeats($seatType),
                    'included' => $seatType === OrganizationSeatType::Administrator
                        ? $team->included_administrator_seats
                        : $team->included_collaborator_seats,
                    'additional' => $seatType === OrganizationSeatType::Administrator
                        ? $team->additional_administrator_seats
                        : $team->additional_collaborator_seats,
                ],
            ])
            ->toArray();

        if (Gate::forUser($user)->allows('requestSeatIncrease', $team)) {
            $pendingSeatIncreaseRequest = $team->seatIncreaseRequests()
                ->with('requester')
                ->where('status', SeatIncreaseRequestStatus::Pending->value)
                ->latest()
                ->first();

            $this->pendingSeatIncreaseRequest = $pendingSeatIncreaseRequest === null
                ? null
                : [
                    'administrator_seats' => $pendingSeatIncreaseRequest->requested_administrator_seats,
                    'collaborator_seats' => $pendingSeatIncreaseRequest->requested_collaborator_seats,
                    'justification' => $pendingSeatIncreaseRequest->justification,
                    'requester' => $pendingSeatIncreaseRequest->requester?->name ?? 'Usuario eliminado',
                    'created_at' => $pendingSeatIncreaseRequest->created_at?->format('d/m/Y H:i'),
                ];

            $this->seatIncreaseRequestHistory = $team->seatIncreaseRequests()
                ->with(['requester', 'reviewer'])
                ->whereIn('status', [
                    SeatIncreaseRequestStatus::Approved->value,
                    SeatIncreaseRequestStatus::Rejected->value,
                ])
                ->latest('reviewed_at')
                ->limit(5)
                ->get()
                ->map(fn ($request) => [
                    'status' => $request->status->value,
                    'status_label' => $request->status->label(),
                    'administrator_seats' => $request->requested_administrator_seats,
                    'collaborator_seats' => $request->requested_collaborator_seats,
                    'requester' => $request->requester?->name ?? 'Usuario eliminado',
                    'reviewer' => $request->reviewer?->name ?? 'Usuario eliminado',
                    'reviewed_at' => $request->reviewed_at?->format('d/m/Y H:i'),
                    'review_notes' => $request->review_notes,
                ])
                ->toArray();
        } else {
            $this->pendingSeatIncreaseRequest = null;
            $this->seatIncreaseRequestHistory = [];
        }

        $this->isCurrentTeam = $user->isCurrentTeam($team);
    }

    public function render()
    {
        $teamName = $this->teamData['name'] ?? $this->teamModel->name;

        $title = $this->permissions->canUpdateTeam
            ? __('Edit :name', ['name' => $teamName])
            : __('View :name', ['name' => $teamName]);

        return $this->view()->title($title);
    }

    #[Computed]
    public function permissions(): TeamPermissions
    {
        return Auth::user()->toTeamPermissions($this->teamModel);
    }

    #[Computed]
    public function canRequestSeatIncrease(): bool
    {
        return Gate::allows('requestSeatIncrease', $this->teamModel);
    }
}; ?>

<section class="mx-auto w-full max-w-7xl space-y-6" data-test="team-main-page">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:badge color="blue">Organización actual</flux:badge>
            <flux:heading size="xl" level="1" class="mt-3">Equipo</flux:heading>
            <flux:subheading class="mt-1">Administra usuarios, roles, cupos y accesos de {{ $teamData['name'] }}.</flux:subheading>
        </div>
        <flux:button :href="route('dashboard')" variant="ghost" icon="arrow-left" wire:navigate>Volver al centro de control</flux:button>
    </header>

    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 md:p-8">
        <div class="space-y-10">
            <div class="space-y-6">
                @if ($this->permissions->canUpdateTeam)
                    <div class="space-y-4">
                        <form wire:submit="updateTeam" class="space-y-6">
                            <flux:input wire:model="teamName" :label="__('Team name')" required data-test="team-name-input" />

                            <flux:button variant="primary" type="submit" data-test="team-save-button">
                                {{ __('Save') }}
                            </flux:button>
                        </form>
                    </div>
                @else
                    <div>
                        <flux:heading>{{ $teamData['name'] }}</flux:heading>
                    </div>
                @endif
            </div>

            @if (! $teamData['is_personal'])
                <div class="space-y-4" data-test="organization-seat-summary">
                    <div>
                        <flux:heading>{{ __('Organization seats') }}</flux:heading>
                        <flux:subheading>{{ __('Active and suspended memberships, plus pending invitations, occupy seats.') }}</flux:subheading>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($seatUsage as $seatType => $usage)
                            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="seat-summary-{{ $seatType }}">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <div class="font-medium">{{ $usage['label'] }}</div>
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                            {{ __(':occupied of :capacity occupied', [
                                                'occupied' => $usage['occupied'],
                                                'capacity' => $usage['capacity'],
                                            ]) }}
                                        </flux:text>
                                    </div>

                                    <flux:badge :color="$usage['available'] > 0 ? 'green' : 'red'">
                                        {{ __('Available: :count', ['count' => $usage['available']]) }}
                                    </flux:badge>
                                </div>

                                <div class="mt-4 flex flex-wrap gap-2 text-sm">
                                    <flux:badge color="zinc">{{ __('Included: :count', ['count' => $usage['included']]) }}</flux:badge>
                                    <flux:badge color="zinc">{{ __('Additional: :count', ['count' => $usage['additional']]) }}</flux:badge>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if ($this->canRequestSeatIncrease && $pendingSeatIncreaseRequest !== null)
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/50 dark:bg-amber-950/30" data-test="pending-seat-increase-request">
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <div class="font-medium text-amber-900 dark:text-amber-100">Solicitud de cupos pendiente</div>
                                    <flux:text class="text-sm text-amber-800 dark:text-amber-200">
                                        Solicitada por {{ $pendingSeatIncreaseRequest['requester'] }} el {{ $pendingSeatIncreaseRequest['created_at'] }}.
                                    </flux:text>
                                </div>
                                <flux:badge color="amber">Pendiente</flux:badge>
                            </div>

                            <div class="mt-3 flex flex-wrap gap-2">
                                <flux:badge color="zinc">Administradores: +{{ $pendingSeatIncreaseRequest['administrator_seats'] }}</flux:badge>
                                <flux:badge color="zinc">Colaboradores: +{{ $pendingSeatIncreaseRequest['collaborator_seats'] }}</flux:badge>
                            </div>

                            <flux:text class="mt-3 text-sm">{{ $pendingSeatIncreaseRequest['justification'] }}</flux:text>
                        </div>
                    @elseif ($this->canRequestSeatIncrease)
                        <div>
                            <flux:modal.trigger name="request-additional-seats">
                                <flux:button variant="outline" icon="plus" data-test="request-additional-seats-button">
                                    Solicitar cupos adicionales
                                </flux:button>
                            </flux:modal.trigger>
                        </div>
                    @endif

                    @if ($this->canRequestSeatIncrease && count($seatIncreaseRequestHistory) > 0)
                        <div class="space-y-3" data-test="seat-increase-request-history">
                            <div class="font-medium">Historial de solicitudes</div>

                            @foreach ($seatIncreaseRequestHistory as $historyItem)
                                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="seat-increase-history-row">
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <div class="flex flex-wrap gap-2">
                                                <flux:badge color="zinc">Administradores: +{{ $historyItem['administrator_seats'] }}</flux:badge>
                                                <flux:badge color="zinc">Colaboradores: +{{ $historyItem['collaborator_seats'] }}</flux:badge>
                                            </div>
                                            <flux:text class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                                                Solicitada por {{ $historyItem['requester'] }} · Revisada por {{ $historyItem['reviewer'] }} el {{ $historyItem['reviewed_at'] }}
                                            </flux:text>
                                        </div>
                                        <flux:badge :color="$historyItem['status'] === 'approved' ? 'green' : 'red'">
                                            {{ $historyItem['status_label'] }}
                                        </flux:badge>
                                    </div>

                                    @if ($historyItem['review_notes'])
                                        <flux:text class="mt-3 text-sm">{{ $historyItem['review_notes'] }}</flux:text>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            <div class="space-y-6">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:heading>{{ __('Team members') }}</flux:heading>
                        @if ($this->permissions->canAddMember || $this->permissions->canUpdateMember || $this->permissions->canRemoveMember)
                            <flux:subheading>{{ __('Manage who belongs to this team') }}</flux:subheading>
                        @endif
                    </div>

                    @if ($this->permissions->canCreateInvitation)
                        <div class="flex flex-wrap gap-2">
                            @if ($this->permissions->canAddMember)
                                <flux:modal.trigger name="create-organization-user">
                                    <flux:button variant="primary" icon="user-plus" data-test="create-organization-user-button">
                                        Crear usuario
                                    </flux:button>
                                </flux:modal.trigger>
                            @endif

                            <flux:modal.trigger name="invite-member">
                                <flux:button variant="outline" icon="envelope" data-test="invite-member-button">
                                    Enviar invitación
                                </flux:button>
                            </flux:modal.trigger>
                        </div>
                    @endif
                </div>

                <div class="space-y-3">
                    @foreach ($members as $member)
                        <div
                            @class([
                                'flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900',
                                'opacity-70' => $member['status'] === 'suspended',
                            ])
                            data-test="member-row"
                        >
                            <div class="flex items-center gap-4">
                                <flux:avatar :name="$member['name']" :initials="$member['initials']" />
                                <div>
                                    <div class="font-medium">{{ $member['name'] }}</div>
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $member['email'] }}</flux:text>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <flux:badge :color="$member['status'] === 'active' ? 'green' : 'amber'" data-test="member-status-badge">
                                    {{ $member['status_label'] }}
                                </flux:badge>

                                @if ($member['role'] !== 'owner' && ! $member['is_self'] && $this->permissions->canUpdateMember)
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button variant="outline" size="sm" icon:trailing="chevron-down" data-test="member-role-trigger">
                                            {{ $member['role_label'] }}
                                        </flux:button>
                                        <flux:menu>
                                            @foreach ($availableRoles as $role)
                                                <flux:menu.item
                                                    as="button"
                                                    type="button"
                                                    wire:click="updateMember({{ $member['id'] }}, '{{ $role['value'] }}')"
                                                    data-test="member-role-option"
                                                >
                                                    {{ $role['label'] }}
                                                </flux:menu.item>
                                            @endforeach
                                        </flux:menu>
                                    </flux:dropdown>
                                @else
                                    <flux:badge color="zinc">{{ $member['role_label'] }}</flux:badge>
                                @endif

                                @if ($member['role'] !== 'owner' && ! $member['is_self'] && $this->permissions->canManageMemberStatus)
                                    @if ($member['status'] === 'active')
                                        <flux:modal.trigger name="suspend-member-{{ $member['id'] }}">
                                            <flux:tooltip :content="__('Suspend member')">
                                                <flux:button
                                                    variant="ghost"
                                                    size="sm"
                                                    icon="pause-circle"
                                                    data-test="member-suspend-button"
                                                />
                                            </flux:tooltip>
                                        </flux:modal.trigger>
                                    @elseif ($member['status'] === 'suspended')
                                        <flux:tooltip :content="__('Reactivate member')">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="play-circle"
                                                wire:click="reactivateMember({{ $member['id'] }})"
                                                data-test="member-reactivate-button"
                                            />
                                        </flux:tooltip>
                                    @endif
                                @endif

                                @if ($member['role'] !== 'owner' && ! $member['is_self'] && $this->permissions->canRemoveMember)
                                    <flux:modal.trigger name="remove-member-{{ $member['id'] }}">
                                        <flux:tooltip :content="__('Remove member')">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="x-mark"
                                                data-test="member-remove-button"
                                            />
                                        </flux:tooltip>
                                    </flux:modal.trigger>
                                @endif
                            </div>
                        </div>

                        @if ($member['role'] !== 'owner' && ! $member['is_self'] && $this->permissions->canManageMemberStatus && $member['status'] === 'active')
                            <flux:modal name="suspend-member-{{ $member['id'] }}" focusable class="max-w-lg">
                                <form wire:submit="suspendMember({{ $member['id'] }})" class="space-y-6">
                                    <div>
                                        <flux:heading size="lg">{{ __('Suspend member') }}</flux:heading>
                                        <flux:subheading>
                                            {{ __('Suspend :name? They will lose access to this organization until reactivated.', ['name' => $member['name']]) }}
                                        </flux:subheading>
                                    </div>

                                    <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                                        <flux:modal.close>
                                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                                        </flux:modal.close>

                                        <flux:button variant="danger" type="submit" data-test="member-suspend-confirm">
                                            {{ __('Suspend member') }}
                                        </flux:button>
                                    </div>
                                </form>
                            </flux:modal>
                        @endif

                        @if ($member['role'] !== 'owner' && ! $member['is_self'] && $this->permissions->canRemoveMember)
                            <livewire:pages::teams.remove-member-modal
                                :team="$teamModel"
                                :member-id="$member['id']"
                                :member-name="$member['name']"
                                :modal-name="'remove-member-'.$member['id']"
                                :key="'remove-member-modal-'.$member['id']"
                            />
                        @endif
                    @endforeach
                </div>
            </div>

            @if (count($invitations) > 0)
                <div class="space-y-6">
                    <div>
                        <flux:heading>{{ __('Pending invitations') }}</flux:heading>
                        <flux:subheading>{{ __('Invitations that have not been accepted yet') }}</flux:subheading>
                    </div>

                    <div class="space-y-3">
                        @foreach ($invitations as $invitation)
                            <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="invitation-row">
                                <div class="flex items-center gap-4">
                                    <div class="flex size-10 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                        <flux:icon name="envelope" class="text-zinc-500" />
                                    </div>
                                    <div>
                                        <div class="font-medium">{{ $invitation['email'] }}</div>
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $invitation['role_label'] }}</flux:text>
                                    </div>
                                </div>

                                @if ($this->permissions->canCancelInvitation)
                                    <flux:modal.trigger name="cancel-invitation-{{ $invitation['code'] }}">
                                        <flux:tooltip :content="__('Cancel invitation')">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="x-mark"
                                                data-test="invitation-cancel-button"
                                            />
                                        </flux:tooltip>
                                    </flux:modal.trigger>
                                @endif
                            </div>
                            @if ($this->permissions->canCancelInvitation)
                                <livewire:pages::teams.cancel-invitation-modal
                                    :team="$teamModel"
                                    :invitation-code="$invitation['code']"
                                    :invitation-email="$invitation['email']"
                                    :modal-name="'cancel-invitation-'.$invitation['code']"
                                    :key="'cancel-invitation-modal-'.$invitation['code']"
                                />
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($this->permissions->canDeleteTeam && ! $teamData['is_personal'])
                <div class="space-y-6">
                    <div>
                        <flux:heading>{{ __('Delete team') }}</flux:heading>
                        <flux:subheading>{{ __('Permanently delete your team') }}</flux:subheading>
                    </div>

                    <div class="space-y-4 rounded-lg border border-red-200 bg-red-50 p-4 text-red-700 dark:border-red-200/10 dark:bg-red-900/20 dark:text-red-100">
                        <div>
                            <p class="font-medium">{{ __('Warning') }}</p>
                            <p class="text-sm">{{ __('Please proceed with caution, this cannot be undone.') }}</p>
                        </div>

                        <flux:modal.trigger name="delete-team">
                            <flux:button variant="danger" data-test="delete-team-button">
                                {{ __('Delete team') }}
                            </flux:button>
                        </flux:modal.trigger>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if ($this->permissions->canCreateInvitation)
        <livewire:pages::teams.invite-member-modal :team="$teamModel" />
    @endif

    @if ($this->permissions->canAddMember)
        <flux:modal name="create-organization-user" :show="$errors->hasAny(['newUserName', 'newUserEmail', 'newUserPassword', 'newUserRole'])" focusable class="max-w-2xl">
            <form wire:submit="createOrganizationUser" class="space-y-6">
                <div>
                    <flux:heading size="lg">Crear usuario de la organización</flux:heading>
                    <flux:subheading>
                        La cuenta quedará lista para iniciar sesión. Si el correo ya existe, se reutilizará su identidad y conservará su contraseña actual.
                    </flux:subheading>
                </div>

                <div class="space-y-4">
                    <flux:input
                        wire:model="newUserName"
                        label="Nombre completo"
                        type="text"
                        required
                        data-test="new-organization-user-name"
                    />
                    <flux:input
                        wire:model="newUserEmail"
                        label="Correo electrónico"
                        type="email"
                        required
                        data-test="new-organization-user-email"
                    />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input
                            wire:model="newUserPassword"
                            label="Contraseña"
                            type="password"
                            viewable
                            required
                            data-test="new-organization-user-password"
                        />
                        <flux:input
                            wire:model="newUserPasswordConfirmation"
                            label="Confirmar contraseña"
                            type="password"
                            viewable
                            required
                            data-test="new-organization-user-password-confirmation"
                        />
                    </div>
                    <flux:select wire:model="newUserRole" label="Rol" data-test="new-organization-user-role">
                        @foreach ($availableRoles as $role)
                            <flux:select.option value="{{ $role['value'] }}">{{ $role['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">Cancelar</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" data-test="create-organization-user-submit">
                        Crear usuario
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($this->permissions->canDeleteTeam && ! $teamData['is_personal'])
        <livewire:pages::teams.delete-team-modal :team="$teamModel" />
    @endif

    @if ($this->canRequestSeatIncrease && ! $teamData['is_personal'])
        <flux:modal name="request-additional-seats" :show="$errors->hasAny(['requestedAdministratorSeats', 'requestedCollaboratorSeats', 'seatIncreaseJustification', 'seatIncreaseRequest'])" focusable class="max-w-lg">
            <form wire:submit="requestAdditionalSeats" class="space-y-6">
                <div>
                    <flux:heading size="lg">Solicitar cupos adicionales</flux:heading>
                    <flux:subheading>Indica los cupos que necesita la organización. El Platform Owner revisará la solicitud.</flux:subheading>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input
                        wire:model="requestedAdministratorSeats"
                        type="number"
                        min="0"
                        step="1"
                        label="Administradores adicionales"
                        required
                        data-test="requested-administrator-seats"
                    />
                    <flux:input
                        wire:model="requestedCollaboratorSeats"
                        type="number"
                        min="0"
                        step="1"
                        label="Colaboradores adicionales"
                        required
                        data-test="requested-collaborator-seats"
                    />
                </div>

                <flux:textarea
                    wire:model="seatIncreaseJustification"
                    label="Justificación"
                    rows="4"
                    maxlength="1000"
                    required
                    data-test="seat-increase-justification"
                />

                @error('seatIncreaseRequest')
                    <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">Cancelar</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" data-test="submit-seat-increase-request">
                        Enviar solicitud
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
