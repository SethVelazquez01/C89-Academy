@php
    $user = auth()->user();
    $team = $user->currentTeam;
    $role = $team !== null ? $user->teamRole($team) : null;
    $firstName = \Illuminate\Support\Str::before($user->name, ' ');
@endphp

<x-layouts::app title="Mi aprendizaje">
    <livewire:pages::teams.pending-invitations-modal />

    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6">
        @if (session('success'))
            <div role="status" class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
                {{ session('success') }}
            </div>
        @endif

        <section class="relative overflow-hidden rounded-3xl border border-[#174667] bg-[#062b4f] p-6 text-white shadow-xl shadow-[#062b4f]/15 md:p-8">
            <div class="pointer-events-none absolute -left-16 -top-24 size-64 rounded-full bg-[#63b32e]/20 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-24 right-48 size-72 rounded-full bg-[#f5b82e]/15 blur-3xl"></div>

            <div class="relative z-10 grid gap-8 md:grid-cols-[minmax(0,1fr)_15rem] md:items-center">
                <div class="max-w-2xl">
                    <span class="inline-flex rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-white">
                        C89 Academy
                    </span>

                    <h1 class="mt-4 text-3xl font-bold tracking-tight text-white md:text-4xl">
                        Bienvenido, {{ $firstName }}
                    </h1>
                    <p class="mt-3 text-base leading-7 text-blue-100">
                        Este es tu espacio para continuar tu capacitación y consultar tus avances.
                    </p>

                    <div class="mt-6 inline-flex min-w-64 items-center gap-3 rounded-xl p-4 backdrop-blur">
                        <div class="flex size-10 items-center justify-center rounded-lg p-1.5">
                            <img src="/images/brand/c89-logo.png" alt="" class="size-full object-contain" />
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-blue-200">Organización actual</p>
                            <p class="mt-0.5 font-semibold text-white">{{ $team?->name ?? 'Sin organización' }}</p>
                            <p class="text-sm text-blue-100">Rol: {{ $role?->label() ?? 'Sin rol asignado' }}</p>
                        </div>
                    </div>
                </div>

                <div class="hidden overflow-hidden rounded-2xl md:block">
                    <img
                        src="/images/brand/c89-mascot.png"
                        alt="Mascota de C89 dando la bienvenida"
                        class="aspect-square w-full object-contain"
                    />
                </div>
            </div>
        </section>

        <section aria-labelledby="learning-summary-title">
            <div class="mb-4">
                <h2 id="learning-summary-title" class="text-xl font-semibold text-zinc-950 dark:text-white">Resumen de aprendizaje</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Tus cifras se actualizan con el estado de tus inscripciones.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Cursos disponibles</p>
                    <p class="mt-2 text-3xl font-bold text-zinc-950 dark:text-white">{{ $availableCourses->count() }}</p>
                </article>
                <article class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">En progreso</p>
                    <p class="mt-2 text-3xl font-bold text-[#3d8d25] dark:text-[#8bd15a]">{{ $activeEnrollmentsCount }}</p>
                </article>
                <article class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Completados</p>
                    <p class="mt-2 text-3xl font-bold text-[#d89a12] dark:text-[#f5b82e]">{{ $completedEnrollmentsCount }}</p>
                </article>
                <article class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Avance general</p>
                    <p class="mt-2 text-3xl font-bold text-zinc-950 dark:text-white">{{ $overallProgressPercentage }}%</p>
                </article>
            </div>
        </section>

        <section aria-labelledby="available-courses-title" class="space-y-4">
            <div>
                <h2 id="available-courses-title" class="text-xl font-semibold text-zinc-950 dark:text-white">Cursos disponibles</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Cursos publicados para {{ $team?->name }}.</p>
            </div>

            @if ($availableCourses->isEmpty())
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 md:p-8">
                    <div class="mx-auto flex max-w-2xl flex-col items-center py-8 text-center">
                        <div class="flex size-16 items-center justify-center rounded-2xl bg-[#63b32e]/15 text-[#3d8d25] dark:text-[#8bd15a]">
                            <flux:icon name="book-open" class="size-8" />
                        </div>
                        <h3 class="mt-5 text-2xl font-semibold text-zinc-950 dark:text-white">No hay cursos publicados</h3>
                        <p class="mt-2 max-w-xl text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                            Cuando un administrador publique un curso de tu organización, aparecerá en esta sección.
                        </p>
                    </div>
                </div>
            @else
                <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                    @foreach ($availableCourses as $course)
                        @php($enrollment = $enrollmentsByCourse->get($course->id))

                        <article class="flex flex-col rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                            <div class="flex items-start justify-between gap-3">
                                @if ($enrollment === null)
                                    <flux:badge color="green">Disponible</flux:badge>
                                @else
                                    <flux:badge color="{{ $enrollment->status === \App\Enums\EnrollmentStatus::Completed ? 'amber' : 'blue' }}">
                                        {{ $enrollment->status->label() }}
                                    </flux:badge>
                                @endif

                                <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $course->estimated_duration_minutes !== null ? $course->estimated_duration_minutes.' min' : 'Duración pendiente' }}
                                </span>
                            </div>
                            <h3 class="mt-5 text-lg font-semibold text-zinc-950 dark:text-white">{{ $course->title }}</h3>
                            <p class="mt-2 flex-1 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ $course->summary ?? 'Consulta próximamente el contenido de este curso.' }}</p>

                            @if (! $canSelfEnroll)
                                <a
                                    href="{{ route('admin.courses.index', ['current_team' => $team]) }}"
                                    class="mt-5 inline-flex justify-center rounded-lg bg-[#062b4f] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#174667]"
                                >
                                    Administrar cursos
                                </a>
                            @elseif ($enrollment === null || $enrollment->status === \App\Enums\EnrollmentStatus::Cancelled)
                                <form method="POST" action="{{ route('courses.enroll', ['current_team' => $team, 'course' => $course]) }}" class="mt-5">
                                    @csrf
                                    <flux:button type="submit" variant="primary" class="w-full">
                                        {{ $enrollment === null ? 'Inscribirme' : 'Volver a inscribirme' }}
                                    </flux:button>
                                </form>
                            @elseif ($enrollment->status === \App\Enums\EnrollmentStatus::Completed)
                                <a
                                    href="{{ route('courses.show', ['current_team' => $team, 'course' => $course]) }}"
                                    class="mt-5 inline-flex justify-center rounded-lg bg-amber-100 px-4 py-2 text-sm font-semibold text-amber-800 transition hover:bg-amber-200 dark:bg-amber-950 dark:text-amber-200 dark:hover:bg-amber-900"
                                    wire:navigate
                                >
                                    Revisar curso completado
                                </a>
                            @else
                                <a
                                    href="{{ route('courses.show', ['current_team' => $team, 'course' => $course]) }}"
                                    class="mt-5 inline-flex justify-center rounded-lg bg-[#062b4f] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#174667]"
                                    wire:navigate
                                >
                                    Continuar curso
                                </a>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-layouts::app>
