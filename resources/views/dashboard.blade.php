@php
    $user = auth()->user();
    $team = $user->currentTeam;
    $role = $team !== null ? $user->teamRole($team) : null;
    $firstName = \Illuminate\Support\Str::before($user->name, ' ');
@endphp

<x-layouts::app title="Mi aprendizaje">
    <livewire:pages::teams.pending-invitations-modal />

    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6">
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

                    <div class="mt-6 inline-flex min-w-64 items-center gap-3 rounded-xl border border-white/15 bg-white/10 p-4 backdrop-blur">
                        <div class="flex size-10 items-center justify-center rounded-lg bg-white p-1.5">
                            <img src="/images/brand/c89-logo.png" alt="" class="size-full object-contain" />
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-blue-200">Organización actual</p>
                            <p class="mt-0.5 font-semibold text-white">{{ $team?->name ?? 'Sin organización' }}</p>
                            <p class="text-sm text-blue-100">Rol: {{ $role?->label() ?? 'Sin rol asignado' }}</p>
                        </div>
                    </div>
                </div>

                <div class="hidden overflow-hidden rounded-2xl border border-white/15 bg-white/10 shadow-2xl md:block">
                    <img
                        src="/images/brand/c89-mascot.png"
                        alt="Mascota de C89 dando la bienvenida"
                        class="aspect-square w-full object-cover"
                    />
                </div>
            </div>
        </section>

        <section aria-labelledby="learning-summary-title">
            <div class="mb-4">
                <h2 id="learning-summary-title" class="text-xl font-semibold text-zinc-950 dark:text-white">Resumen de aprendizaje</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Tu actividad aparecerá aquí cuando tengas cursos asignados.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Cursos asignados</p>
                    <p class="mt-2 text-3xl font-bold text-zinc-950 dark:text-white">0</p>
                </article>
                <article class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">En progreso</p>
                    <p class="mt-2 text-3xl font-bold text-[#3d8d25] dark:text-[#8bd15a]">0</p>
                </article>
                <article class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Completados</p>
                    <p class="mt-2 text-3xl font-bold text-[#d89a12] dark:text-[#f5b82e]">0</p>
                </article>
                <article class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Avance general</p>
                    <p class="mt-2 text-3xl font-bold text-zinc-950 dark:text-white">0%</p>
                </article>
            </div>
        </section>

        <section aria-labelledby="continue-learning-title" class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 md:p-8">
            <div class="mx-auto flex max-w-2xl flex-col items-center py-8 text-center">
                <div class="flex size-16 items-center justify-center rounded-2xl bg-[#63b32e]/15 text-[#3d8d25] dark:text-[#8bd15a]">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="size-8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M4 19.5A2.5 2.5 0 0 0 6.5 22H20V4H6.5A2.5 2.5 0 0 0 4 6.5v13Z" />
                    </svg>
                </div>

                <h2 id="continue-learning-title" class="mt-5 text-2xl font-semibold text-zinc-950 dark:text-white">
                    Aún no tienes cursos asignados
                </h2>
                <p class="mt-2 max-w-xl text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                    Cuando un administrador publique y te asigne un curso, podrás iniciarlo y consultar aquí tu progreso.
                </p>

                <span class="mt-6 inline-flex cursor-not-allowed items-center rounded-lg bg-zinc-100 px-4 py-2 text-sm font-semibold text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                    Catálogo disponible próximamente
                </span>
            </div>
        </section>
    </div>
</x-layouts::app>
