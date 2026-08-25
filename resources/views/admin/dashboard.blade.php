@php
    $user = auth()->user();
    $team = $user->currentTeam;
    $firstName = \Illuminate\Support\Str::before($user->name, ' ');
    $administratorSeatPercentage = $administratorSeats['capacity'] > 0
        ? min((int) round(($administratorSeats['occupied'] / $administratorSeats['capacity']) * 100), 100)
        : 0;
    $collaboratorSeatPercentage = $collaboratorSeats['capacity'] > 0
        ? min((int) round(($collaboratorSeats['occupied'] / $collaboratorSeats['capacity']) * 100), 100)
        : 0;
@endphp

<x-layouts::app title="Centro de control">
    <livewire:pages::teams.pending-invitations-modal />

    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6" data-test="admin-dashboard">
        <section class="relative overflow-hidden rounded-3xl border border-[#174667] bg-[#062b4f] p-6 text-white shadow-xl shadow-[#062b4f]/15 md:p-8">
            <div class="pointer-events-none absolute -left-20 -top-24 size-64 rounded-full bg-[#63b32e]/20 blur-3xl"></div>
            <div class="relative z-10 flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                <div class="max-w-2xl">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-wide">Administración segura</span>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $twoFactorEnabled ? 'bg-[#63b32e] text-white' : 'bg-amber-400 text-[#062b4f]' }}">
                            {{ $twoFactorEnabled ? '2FA activo' : '2FA pendiente' }}
                        </span>
                    </div>
                    <h1 class="mt-4 text-3xl font-bold tracking-tight md:text-4xl">Hola, {{ $firstName }}</h1>
                    <p class="mt-3 text-blue-100">Controla el contenido y los accesos de {{ $team->name }} desde un panel separado del aprendizaje de colaboradores.</p>
                </div>

                <div class="flex flex-wrap gap-3">
                    @can('create', \App\Models\Course::class)
                        <flux:button :href="route('admin.courses.create')" variant="primary" icon="plus" wire:navigate>Nuevo curso</flux:button>
                    @endcan
                    @can('create', \App\Models\Exam::class)
                        <flux:button :href="route('admin.exams.create')" variant="filled" icon="clipboard-document-check" wire:navigate>Nuevo examen</flux:button>
                    @endcan
                </div>
            </div>
        </section>

        @unless ($twoFactorEnabled)
            <section class="flex flex-col gap-4 rounded-2xl border border-amber-300 bg-amber-50 p-5 dark:border-amber-800 dark:bg-amber-950/40 sm:flex-row sm:items-center sm:justify-between" data-test="admin-security-warning">
                <div class="flex gap-3">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-200 text-amber-900 dark:bg-amber-900 dark:text-amber-100">
                        <flux:icon name="shield-exclamation" class="size-5" />
                    </div>
                    <div>
                        <h2 class="font-semibold text-amber-950 dark:text-amber-100">Protege tu cuenta administrativa</h2>
                        <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">Activa la autenticación en dos pasos antes de administrar usuarios o publicar evaluaciones.</p>
                    </div>
                </div>
                <flux:button :href="route('security.edit')" variant="primary" wire:navigate>Configurar seguridad</flux:button>
            </section>
        @endunless

        <section aria-labelledby="admin-summary-title">
            <div class="mb-4">
                <h2 id="admin-summary-title" class="text-xl font-semibold text-zinc-950 dark:text-white">Resumen operativo</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Sólo se muestran datos de la organización seleccionada.</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between"><span class="text-sm text-zinc-500">Usuarios activos</span><flux:icon name="users" class="size-5 text-[#63b32e]" /></div>
                    <p class="mt-3 text-3xl font-bold">{{ $activeMembersCount }}</p>
                    <p class="mt-1 text-xs text-zinc-500">{{ $pendingInvitationsCount }} invitaciones pendientes</p>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between"><span class="text-sm text-zinc-500">Cursos publicados</span><flux:icon name="book-open" class="size-5 text-blue-500" /></div>
                    <p class="mt-3 text-3xl font-bold">{{ $publishedCoursesCount }}</p>
                    <p class="mt-1 text-xs text-zinc-500">{{ $draftCoursesCount }} borradores</p>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between"><span class="text-sm text-zinc-500">Exámenes publicados</span><flux:icon name="clipboard-document-check" class="size-5 text-amber-500" /></div>
                    <p class="mt-3 text-3xl font-bold">{{ $publishedExamsCount }}</p>
                    <p class="mt-1 text-xs text-zinc-500">{{ $pendingExamReviewsCount }} por revisar · {{ $draftExamsCount }} borradores</p>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between"><span class="text-sm text-zinc-500">Estado de acceso</span><flux:icon name="shield-check" class="size-5 {{ $twoFactorEnabled ? 'text-green-500' : 'text-amber-500' }}" /></div>
                    <p class="mt-3 text-lg font-bold">{{ $twoFactorEnabled ? 'Protegido con 2FA' : 'Requiere atención' }}</p>
                    <p class="mt-1 text-xs text-zinc-500">Correo verificado y sesión autenticada</p>
                </article>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <section class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-4">
                    <div><h2 class="text-lg font-semibold">Contenido reciente</h2><p class="text-sm text-zinc-500">Cursos y exámenes editados recientemente.</p></div>
                </div>
                <div class="mt-5 grid gap-5 lg:grid-cols-2">
                    <div class="space-y-3">
                        <div class="flex items-center justify-between"><h3 class="font-medium">Cursos</h3><a href="{{ route('admin.courses.index') }}" class="text-sm font-medium text-[#3d8d25]" wire:navigate>Ver todos</a></div>
                        @forelse ($recentCourses as $course)
                            <a href="{{ route('admin.courses.edit', ['course' => $course]) }}" class="flex items-center justify-between rounded-xl border border-zinc-200 p-3 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800" wire:navigate>
                                <span class="truncate text-sm font-medium">{{ $course->title }}</span><flux:badge :color="$course->status === \App\Enums\CourseStatus::Published ? 'green' : 'zinc'">{{ $course->status->label() }}</flux:badge>
                            </a>
                        @empty
                            <p class="rounded-xl border border-dashed border-zinc-300 p-4 text-sm text-zinc-500">Aún no hay cursos.</p>
                        @endforelse
                    </div>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between"><h3 class="font-medium">Exámenes</h3><a href="{{ route('admin.exams.index') }}" class="text-sm font-medium text-[#3d8d25]" wire:navigate>Ver todos</a></div>
                        @forelse ($recentExams as $exam)
                            <a href="{{ route('admin.exams.edit', ['exam' => $exam]) }}" class="flex items-center justify-between rounded-xl border border-zinc-200 p-3 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800" wire:navigate>
                                <span class="min-w-0"><span class="block truncate text-sm font-medium">{{ $exam->title }}</span><span class="text-xs text-zinc-500">{{ $exam->questions_count }} preguntas</span></span><flux:badge :color="$exam->status === \App\Enums\ExamStatus::Published ? 'green' : 'zinc'">{{ $exam->status->label() }}</flux:badge>
                            </a>
                        @empty
                            <p class="rounded-xl border border-dashed border-zinc-300 p-4 text-sm text-zinc-500">Aún no hay exámenes.</p>
                        @endforelse
                    </div>
                </div>
            </section>

            <aside class="space-y-6">
                <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between"><h2 class="font-semibold">Cupos</h2><a href="{{ route('teams.edit', ['team' => $team]) }}" class="text-sm font-medium text-[#3d8d25]" wire:navigate>Gestionar equipo</a></div>
                    <div class="mt-5 space-y-5">
                        <div><div class="flex justify-between text-sm"><span>Administradores</span><span>{{ $administratorSeats['occupied'] }}/{{ $administratorSeats['capacity'] }}</span></div><div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"><div class="h-full rounded-full bg-blue-500" style="width: {{ $administratorSeatPercentage }}%"></div></div></div>
                        <div><div class="flex justify-between text-sm"><span>Colaboradores</span><span>{{ $collaboratorSeats['occupied'] }}/{{ $collaboratorSeats['capacity'] }}</span></div><div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"><div class="h-full rounded-full bg-[#63b32e]" style="width: {{ $collaboratorSeatPercentage }}%"></div></div></div>
                    </div>
                </section>
                <section class="rounded-2xl border border-[#174667] bg-[#062b4f] p-5 text-white">
                    <div class="flex items-center gap-3"><flux:icon name="lock-closed" class="size-5 text-[#8bd15a]" /><h2 class="font-semibold">Controles aplicados</h2></div>
                    <ul class="mt-4 space-y-2 text-sm text-blue-100"><li>• Aislamiento por organización</li><li>• Acciones protegidas por permisos</li><li>• Membresía activa obligatoria</li><li>• Publicación separada de edición</li></ul>
                </section>
            </aside>
        </div>
    </div>
</x-layouts::app>
