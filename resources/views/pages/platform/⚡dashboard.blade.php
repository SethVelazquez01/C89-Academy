<?php

use App\Actions\Teams\CreateTeam;
use App\Actions\Teams\ProvisionOrganizationUser;
use App\Enums\CourseStatus;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationSeatType;
use App\Enums\SeatIncreaseRequestStatus;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\SeatIncreaseRequest;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Rules\TeamName;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Control de plataforma')] class extends Component
{
    public string $search = '';

    public string $organizationName = '';

    public string $organizationAdministratorSeats = '1';

    public string $organizationCollaboratorSeats = '1';

    public ?int $administratorOrganizationId = null;

    public string $administratorName = '';

    public string $administratorEmail = '';

    public string $administratorPassword = '';

    public string $administratorPasswordConfirmation = '';

    public function mount(): void
    {
        abort_unless(Auth::user()?->isPlatformOwner(), 403);
    }

    public function createOrganization(CreateTeam $createTeam): void
    {
        $validated = $this->validate([
            'organizationName' => ['required', 'string', 'max:255', new TeamName],
            'organizationAdministratorSeats' => ['required', 'integer', 'min:1', 'max:100000'],
            'organizationCollaboratorSeats' => ['required', 'integer', 'min:0', 'max:100000'],
        ], [
            'organizationName.required' => 'El nombre de la empresa es obligatorio.',
            'organizationName.max' => 'El nombre no puede superar 255 caracteres.',
            'organizationAdministratorSeats.required' => 'Define al menos un cupo de administrador.',
            'organizationAdministratorSeats.integer' => 'Los cupos de administradores deben ser un número entero.',
            'organizationAdministratorSeats.min' => 'La empresa necesita al menos un cupo de administrador.',
            'organizationCollaboratorSeats.required' => 'Define los cupos de colaboradores.',
            'organizationCollaboratorSeats.integer' => 'Los cupos de colaboradores deben ser un número entero.',
            'organizationCollaboratorSeats.min' => 'Los cupos de colaboradores no pueden ser negativos.',
        ]);

        $organization = $createTeam->handle(
            Auth::user(),
            $validated['organizationName'],
            includedAdministratorSeats: (int) $validated['organizationAdministratorSeats'],
            includedCollaboratorSeats: (int) $validated['organizationCollaboratorSeats'],
        );

        $this->reset('organizationName', 'organizationAdministratorSeats', 'organizationCollaboratorSeats');
        $this->organizationAdministratorSeats = '1';
        $this->organizationCollaboratorSeats = '1';
        unset($this->organizations, $this->organizationOptions, $this->overview);
        Flux::modal('platform-create-organization')->close();
        Flux::toast(variant: 'success', text: "Empresa {$organization->name} creada correctamente.");
    }

    public function createAdministrator(ProvisionOrganizationUser $provisionOrganizationUser): void
    {
        $validated = $this->validate([
            'administratorOrganizationId' => [
                'required',
                'integer',
                Rule::exists('teams', 'id')->where('is_personal', 0)->whereNull('deleted_at'),
            ],
            'administratorName' => ['required', 'string', 'max:255'],
            'administratorEmail' => ['required', 'string', 'email', 'max:255'],
            'administratorPassword' => ['required', 'string', 'min:8', 'confirmed:administratorPasswordConfirmation'],
        ], [
            'administratorOrganizationId.required' => 'Selecciona una empresa.',
            'administratorOrganizationId.exists' => 'La empresa seleccionada no está disponible.',
            'administratorName.required' => 'El nombre del administrador es obligatorio.',
            'administratorEmail.required' => 'El correo electrónico es obligatorio.',
            'administratorEmail.email' => 'El correo electrónico no tiene un formato válido.',
            'administratorPassword.required' => 'La contraseña es obligatoria.',
            'administratorPassword.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'administratorPassword.confirmed' => 'La confirmación de contraseña no coincide.',
        ]);

        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($validated['administratorOrganizationId']);

        $provisionOrganizationUser->handle(
            Auth::user(),
            $organization,
            $validated['administratorName'],
            $validated['administratorEmail'],
            TeamRole::Admin->value,
            $validated['administratorPassword'],
        );

        $this->reset(
            'administratorOrganizationId',
            'administratorName',
            'administratorEmail',
            'administratorPassword',
            'administratorPasswordConfirmation',
        );
        unset($this->organizations, $this->overview);
        Flux::modal('platform-create-administrator')->close();
        Flux::toast(
            variant: 'success',
            text: 'Administrador creado y listo para iniciar sesión.',
        );
    }

    /**
     * @return Collection<int, Team>
     */
    #[Computed]
    public function organizations(): Collection
    {
        return $this->organizationMetrics($this->search);
    }

    /**
     * @return Collection<int, Team>
     */
    #[Computed]
    public function organizationOptions(): Collection
    {
        return Team::query()
            ->where('is_personal', false)
            ->orderByRaw('LOWER(name)')
            ->get(['id', 'name']);
    }

    /**
     * @return Collection<int, Team>
     */
    private function organizationMetrics(?string $search = null): Collection
    {
        $organizations = Team::query()
            ->where('is_personal', false)
            ->when(filled($search), fn ($query) => $query
                ->whereRaw('LOWER(name) LIKE ?', ['%'.Str::lower(trim((string) $search)).'%']))
            ->withCount([
                'memberships as active_members_count' => fn ($query) => $query
                    ->where('status', MembershipStatus::Active->value),
                'memberships as suspended_members_count' => fn ($query) => $query
                    ->where('status', MembershipStatus::Suspended->value),
                'memberships as occupied_administrator_memberships_count' => fn ($query) => $query
                    ->whereIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
                    ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Suspended->value]),
                'memberships as occupied_collaborator_memberships_count' => fn ($query) => $query
                    ->where('role', TeamRole::Member->value)
                    ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Suspended->value]),
                'invitations as pending_invitations_count' => fn ($query) => $this->pendingInvitationQuery($query),
                'invitations as pending_administrator_invitations_count' => fn ($query) => $this
                    ->pendingInvitationQuery($query)
                    ->whereIn('role', [TeamRole::Owner->value, TeamRole::Admin->value]),
                'invitations as pending_collaborator_invitations_count' => fn ($query) => $this
                    ->pendingInvitationQuery($query)
                    ->where('role', TeamRole::Member->value),
                'courses',
                'courses as published_courses_count' => fn ($query) => $query
                    ->where('status', CourseStatus::Published->value),
            ])
            ->orderByRaw('LOWER(name)')
            ->get();

        $organizationIds = $organizations->pluck('id');
        $contentByOrganization = DB::table('courses')
            ->leftJoin('course_modules', function ($join): void {
                $join->on('course_modules.course_id', '=', 'courses.id')
                    ->whereNull('course_modules.deleted_at');
            })
            ->leftJoin('lessons', function ($join): void {
                $join->on('lessons.course_module_id', '=', 'course_modules.id')
                    ->whereNull('lessons.deleted_at');
            })
            ->whereNull('courses.deleted_at')
            ->whereIn('courses.team_id', $organizationIds)
            ->groupBy('courses.team_id')
            ->selectRaw('courses.team_id')
            ->selectRaw('COUNT(DISTINCT course_modules.id) as modules_count')
            ->selectRaw('COUNT(DISTINCT lessons.id) as lessons_count')
            ->selectRaw('COALESCE(SUM(lessons.resource_size), 0) as storage_bytes')
            ->selectRaw('MAX(COALESCE(lessons.updated_at, course_modules.updated_at, courses.updated_at)) as last_content_activity')
            ->get()
            ->keyBy('team_id');
        $lastMembershipActivity = Membership::query()
            ->whereIn('team_id', $organizationIds)
            ->selectRaw('team_id, MAX(updated_at) as last_membership_activity')
            ->groupBy('team_id')
            ->get()
            ->keyBy('team_id');

        return $organizations->map(function (Team $organization) use ($contentByOrganization, $lastMembershipActivity): Team {
            $content = $contentByOrganization->get($organization->id);
            $membershipActivity = $lastMembershipActivity->get($organization->id);
            $coursesCount = (int) $organization->courses_count;
            $lessonsCount = (int) ($content?->lessons_count ?? 0);
            $administratorOccupied = (int) $organization->occupied_administrator_memberships_count
                + (int) $organization->pending_administrator_invitations_count;
            $collaboratorOccupied = (int) $organization->occupied_collaborator_memberships_count
                + (int) $organization->pending_collaborator_invitations_count;
            $lastActivity = collect([
                $organization->updated_at,
                $content?->last_content_activity,
                $membershipActivity?->last_membership_activity,
            ])
                ->filter()
                ->map(fn ($date) => Carbon::parse($date))
                ->sortDesc()
                ->first();

            $organization->setAttribute('modules_count', (int) ($content?->modules_count ?? 0));
            $organization->setAttribute('lessons_count', $lessonsCount);
            $organization->setAttribute('average_lessons_per_course', $coursesCount > 0
                ? round($lessonsCount / $coursesCount, 1)
                : 0);
            $organization->setAttribute('storage_bytes', (int) ($content?->storage_bytes ?? 0));
            $organization->setAttribute('administrator_seats_occupied', $administratorOccupied);
            $organization->setAttribute(
                'administrator_seats_capacity',
                $organization->seatCapacity(OrganizationSeatType::Administrator),
            );
            $organization->setAttribute('collaborator_seats_occupied', $collaboratorOccupied);
            $organization->setAttribute(
                'collaborator_seats_capacity',
                $organization->seatCapacity(OrganizationSeatType::Collaborator),
            );
            $organization->setAttribute('last_activity_at', $lastActivity);

            return $organization;
        });
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function overview(): array
    {
        $organizations = $this->organizationMetrics();
        $realOrganizationIds = Team::query()
            ->where('is_personal', false)
            ->pluck('id');
        $activeUsers = Membership::query()
            ->whereIn('team_id', $realOrganizationIds)
            ->where('status', MembershipStatus::Active->value)
            ->distinct('user_id')
            ->count('user_id');
        $pendingSeatRequests = SeatIncreaseRequest::query()
            ->where('status', SeatIncreaseRequestStatus::Pending->value)
            ->count();

        return [
            'organizations' => $organizations->count(),
            'active_users' => $activeUsers,
            'courses' => $organizations->sum(fn (Team $team): int => (int) $team->courses_count),
            'published_courses' => $organizations->sum(fn (Team $team): int => (int) $team->published_courses_count),
            'lessons' => $organizations->sum(fn (Team $team): int => (int) $team->lessons_count),
            'storage_bytes' => $organizations->sum(fn (Team $team): int => (int) $team->storage_bytes),
            'pending_invitations' => TeamInvitation::query()
                ->whereIn('team_id', $realOrganizationIds)
                ->whereNull('accepted_at')
                ->where(fn ($query) => $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now()))
                ->count(),
            'suspended_memberships' => $organizations->sum(fn (Team $team): int => (int) $team->suspended_members_count),
            'organizations_without_admin' => $organizations
                ->filter(fn (Team $team): bool => (int) $team->administrator_seats_occupied === 0)
                ->count(),
            'pending_seat_requests' => $pendingSeatRequests,
        ];
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;

        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'TB') {
                return number_format($value, $value >= 10 ? 0 : 1, ',', '.').' '.$unit;
            }

            $value /= 1024;
        }

        return $bytes.' B';
    }

    private function pendingInvitationQuery($query)
    {
        return $query
            ->whereNull('accepted_at')
            ->where(fn ($pendingQuery) => $pendingQuery
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()));
    }
};
?>

<div class="mx-auto flex w-full max-w-[95rem] flex-1 flex-col gap-6" data-test="platform-dashboard">
    <section class="relative overflow-hidden rounded-3xl border border-[#174667] bg-[#062b4f] p-6 text-white shadow-xl shadow-[#062b4f]/15 md:p-8">
        <div class="pointer-events-none absolute -left-20 -top-28 size-72 rounded-full bg-[#63b32e]/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 right-20 size-80 rounded-full bg-[#f5b82e]/15 blur-3xl"></div>

        <div class="relative z-10 flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
            <div class="max-w-3xl">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="inline-flex rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-blue-100">
                        Control global
                    </span>
                    <span class="inline-flex items-center gap-2 text-xs font-medium text-blue-100">
                        <span class="size-2 rounded-full bg-[#8bd15a]"></span>
                        Plataforma operativa
                    </span>
                </div>
                <h1 class="mt-5 text-3xl font-bold tracking-tight text-white md:text-4xl">Centro de control C89 Academy</h1>
                <p class="mt-3 max-w-2xl text-base leading-7 text-blue-100">
                    Supervisa organizaciones, capacidad, usuarios y uso de contenido desde una sola vista, sin ingresar a la operación interna de cada empresa.
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <flux:modal.trigger name="platform-create-administrator">
                    <flux:button variant="outline" icon="user-plus" class="border-white/30 bg-white/10 text-white hover:bg-white/20">
                        Crear administrador
                    </flux:button>
                </flux:modal.trigger>
                <flux:modal.trigger name="platform-create-organization">
                    <flux:button variant="primary" icon="plus">Nueva empresa</flux:button>
                </flux:modal.trigger>
            </div>
        </div>
    </section>

    <section aria-labelledby="platform-overview-title">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="platform-overview-title" class="text-xl font-semibold text-zinc-950 dark:text-white">Resumen de plataforma</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Cifras consolidadas de las organizaciones reales.</p>
            </div>
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Actualizado {{ now()->format('d/m/Y H:i') }}</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <span class="flex size-10 items-center justify-center rounded-xl bg-blue-100 text-[#174667] dark:bg-blue-950 dark:text-blue-200"><flux:icon name="building-office" class="size-5" /></span>
                    <span class="text-xs font-medium text-zinc-400">Empresas</span>
                </div>
                <p class="mt-5 text-3xl font-bold text-zinc-950 dark:text-white">{{ $this->overview['organizations'] }}</p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">organizaciones administradas</p>
            </article>
            <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <span class="flex size-10 items-center justify-center rounded-xl bg-[#63b32e]/15 text-[#3d8d25] dark:text-[#8bd15a]"><flux:icon name="users" class="size-5" /></span>
                    <span class="text-xs font-medium text-zinc-400">Identidades</span>
                </div>
                <p class="mt-5 text-3xl font-bold text-zinc-950 dark:text-white">{{ $this->overview['active_users'] }}</p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">usuarios activos únicos</p>
            </article>
            <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <span class="flex size-10 items-center justify-center rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-200"><flux:icon name="book-open" class="size-5" /></span>
                    <span class="text-xs font-medium text-zinc-400">Contenido</span>
                </div>
                <p class="mt-5 text-3xl font-bold text-zinc-950 dark:text-white">{{ $this->overview['courses'] }}</p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">cursos · {{ $this->overview['lessons'] }} lecciones</p>
            </article>
            <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <span class="flex size-10 items-center justify-center rounded-xl bg-purple-100 text-purple-700 dark:bg-purple-950 dark:text-purple-200"><flux:icon name="circle-stack" class="size-5" /></span>
                    <span class="text-xs font-medium text-zinc-400">Recursos</span>
                </div>
                <p class="mt-5 text-3xl font-bold text-zinc-950 dark:text-white">{{ $this->formatBytes($this->overview['storage_bytes']) }}</p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">archivos registrados en cursos</p>
            </article>
        </div>
    </section>

    <section class="grid gap-4 lg:grid-cols-[minmax(0,1.55fr)_minmax(19rem,0.75fr)]">
        <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 md:p-6">
            <div class="flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-200"><flux:icon name="exclamation-triangle" class="size-5" /></span>
                <div>
                    <h2 class="font-semibold text-zinc-950 dark:text-white">Atención requerida</h2>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Elementos que pueden necesitar seguimiento del owner.</p>
                </div>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    ['value' => $this->overview['organizations_without_admin'], 'label' => 'Sin administrador'],
                    ['value' => $this->overview['pending_invitations'], 'label' => 'Invitaciones pendientes'],
                    ['value' => $this->overview['suspended_memberships'], 'label' => 'Membresías suspendidas'],
                    ['value' => $this->overview['pending_seat_requests'], 'label' => 'Solicitudes de cupos'],
                ] as $alert)
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/70">
                        <p class="text-2xl font-bold {{ $alert['value'] > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-zinc-950 dark:text-white' }}">{{ $alert['value'] }}</p>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $alert['label'] }}</p>
                    </div>
                @endforeach
            </div>
        </article>

        <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 md:p-6">
            <h2 class="font-semibold text-zinc-950 dark:text-white">Acciones rápidas</h2>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Operaciones frecuentes de administración global.</p>
            <div class="mt-5 grid gap-3">
                <flux:modal.trigger name="platform-create-organization">
                    <flux:button variant="outline" icon="building-office" class="w-full justify-start">Registrar una empresa</flux:button>
                </flux:modal.trigger>
                <flux:modal.trigger name="platform-create-administrator">
                    <flux:button variant="outline" icon="user-plus" class="w-full justify-start">Crear administrador</flux:button>
                </flux:modal.trigger>
                <flux:button :href="route('teams.index')" variant="ghost" icon="cog-6-tooth" class="w-full justify-start" wire:navigate>
                    Administración detallada
                </flux:button>
            </div>
        </article>
    </section>

    <section aria-labelledby="organization-control-title" class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-4 border-b border-zinc-200 p-5 dark:border-zinc-700 md:flex-row md:items-end md:justify-between md:p-6">
            <div>
                <h2 id="organization-control-title" class="text-xl font-semibold text-zinc-950 dark:text-white">Control por organización</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Compara capacidad, actividad y volumen de contenido sin abrir los cursos.</p>
            </div>
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Buscar empresa…" class="w-full md:max-w-xs" />
        </div>

        @if ($this->organizations->isEmpty())
            <div class="p-10 text-center">
                <flux:icon name="building-office" class="mx-auto size-10 text-zinc-400" />
                <h3 class="mt-4 font-semibold text-zinc-950 dark:text-white">No hay empresas para mostrar</h3>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Crea la primera empresa o cambia el término de búsqueda.</p>
            </div>
        @else
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->organizations as $organization)
                    @php
                        $administratorPercentage = $organization->administrator_seats_capacity > 0
                            ? min(100, (int) round(($organization->administrator_seats_occupied / $organization->administrator_seats_capacity) * 100))
                            : 0;
                        $collaboratorPercentage = $organization->collaborator_seats_capacity > 0
                            ? min(100, (int) round(($organization->collaborator_seats_occupied / $organization->collaborator_seats_capacity) * 100))
                            : 0;
                    @endphp
                    <article wire:key="platform-organization-{{ $organization->id }}" class="grid gap-5 p-5 transition hover:bg-zinc-50/80 dark:hover:bg-zinc-800/40 md:p-6 xl:grid-cols-[minmax(14rem,1.15fr)_minmax(17rem,1fr)_minmax(15rem,0.9fr)_auto] xl:items-center" data-test="platform-organization-row">
                        <div class="min-w-0">
                            <div class="flex items-center gap-3">
                                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-[#062b4f] font-bold text-white">{{ Str::upper(Str::substr($organization->name, 0, 2)) }}</span>
                                <div class="min-w-0">
                                    <h3 class="truncate font-semibold text-zinc-950 dark:text-white">{{ $organization->name }}</h3>
                                    <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $organization->slug }}</p>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <flux:badge color="green">{{ $organization->active_members_count }} activos</flux:badge>
                                @if ($organization->suspended_members_count > 0)
                                    <flux:badge color="amber">{{ $organization->suspended_members_count }} suspendidos</flux:badge>
                                @endif
                                @if ($organization->pending_invitations_count > 0)
                                    <flux:badge color="blue">{{ $organization->pending_invitations_count }} invitaciones</flux:badge>
                                @endif
                            </div>
                        </div>

                        <div class="space-y-3">
                            <div>
                                <div class="flex justify-between text-xs text-zinc-500 dark:text-zinc-400">
                                    <span>Administradores</span>
                                    <span class="font-semibold">{{ $organization->administrator_seats_occupied }}/{{ $organization->administrator_seats_capacity }}</span>
                                </div>
                                <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"><div class="h-full rounded-full bg-[#f5b82e]" style="width: {{ $administratorPercentage }}%"></div></div>
                            </div>
                            <div>
                                <div class="flex justify-between text-xs text-zinc-500 dark:text-zinc-400">
                                    <span>Colaboradores</span>
                                    <span class="font-semibold">{{ $organization->collaborator_seats_occupied }}/{{ $organization->collaborator_seats_capacity }}</span>
                                </div>
                                <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"><div class="h-full rounded-full bg-[#63b32e]" style="width: {{ $collaboratorPercentage }}%"></div></div>
                            </div>
                        </div>

                        <div>
                            <p class="font-semibold text-zinc-950 dark:text-white">{{ $organization->courses_count }} cursos · {{ $organization->lessons_count }} lecciones</p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $organization->published_courses_count }} publicados · promedio {{ number_format($organization->average_lessons_per_course, 1, ',', '.') }} lecciones/curso
                            </p>
                            <p class="mt-2 text-sm font-medium text-purple-700 dark:text-purple-300">{{ $this->formatBytes($organization->storage_bytes) }} en recursos</p>
                            <p class="mt-1 text-xs text-zinc-400">
                                Actividad {{ $organization->last_activity_at?->locale('es')->diffForHumans() ?? 'sin registrar' }}
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2 xl:justify-end">
                            <flux:button
                                size="sm"
                                variant="outline"
                                icon="user-plus"
                                x-on:click="$wire.administratorOrganizationId = {{ $organization->id }}; $flux.modal('platform-create-administrator').show()"
                            >
                                Administrador
                            </flux:button>
                            <flux:button :href="route('teams.index')" size="sm" variant="ghost" icon="cog-6-tooth" wire:navigate>
                                Administrar
                            </flux:button>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <flux:modal name="platform-create-organization" :show="$errors->hasAny(['organizationName', 'organizationAdministratorSeats', 'organizationCollaboratorSeats'])" focusable class="max-w-2xl">
        <form wire:submit="createOrganization" class="space-y-6">
            <div>
                <flux:heading size="lg">Registrar nueva empresa</flux:heading>
                <flux:subheading class="mt-1">La empresa se crea aislada y con sus cupos incluidos iniciales.</flux:subheading>
            </div>
            <flux:input wire:model="organizationName" label="Nombre de la empresa" required autofocus data-test="platform-organization-name" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input
                    wire:model="organizationAdministratorSeats"
                    type="number"
                    min="1"
                    max="100000"
                    label="Cupos de administradores"
                    description="Incluye cuentas activas, suspendidas e invitaciones pendientes."
                    required
                    data-test="platform-organization-administrator-seats"
                />
                <flux:input
                    wire:model="organizationCollaboratorSeats"
                    type="number"
                    min="0"
                    max="100000"
                    label="Cupos de colaboradores"
                    description="Usuarios que tomarán cursos dentro de la empresa."
                    required
                    data-test="platform-organization-collaborator-seats"
                />
            </div>
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-3 text-xs leading-5 text-blue-800 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-200">
                Estos valores forman el paquete inicial. Los cupos adicionales seguirán administrándose mediante solicitudes aprobadas por el Platform Owner.
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="plus" data-test="platform-create-organization-submit">Crear empresa</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="platform-create-administrator" :show="$errors->hasAny(['administratorOrganizationId', 'administratorName', 'administratorEmail', 'administratorPassword'])" focusable class="max-w-2xl">
        <form wire:submit="createAdministrator" class="space-y-6">
            <div>
                <flux:heading size="lg">Crear administrador</flux:heading>
                <flux:subheading class="mt-1">Asigna una cuenta administrativa sin convertir al Platform Owner en miembro.</flux:subheading>
            </div>
            <flux:select wire:model="administratorOrganizationId" label="Empresa" required data-test="platform-administrator-organization">
                <flux:select.option value="">Selecciona una empresa</flux:select.option>
                @foreach ($this->organizationOptions as $organization)
                    <flux:select.option :value="$organization->id">{{ $organization->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="administratorName" label="Nombre completo" required data-test="platform-administrator-name" />
            <flux:input wire:model="administratorEmail" type="email" label="Correo electrónico" required data-test="platform-administrator-email" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="administratorPassword" type="password" label="Contraseña" viewable required data-test="platform-administrator-password" />
                <flux:input wire:model="administratorPasswordConfirmation" type="password" label="Confirmar contraseña" viewable required data-test="platform-administrator-password-confirmation" />
            </div>
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-3 text-xs leading-5 text-blue-800 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-200">
                Si el correo ya existe, se conservará su contraseña actual. Si es nuevo, podrá iniciar sesión inmediatamente con la contraseña indicada.
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="user-plus" data-test="platform-create-administrator-submit">Crear administrador</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
