@php
    $user = auth()->user();
    $team = $user->currentTeam;
    $role = $team !== null ? $user->teamRole($team)?->value : null;
    $roleLabel = match ($role) {
        'owner' => 'Propietario',
        'admin' => 'Administrador',
        'member' => 'Colaborador',
        default => 'Sin rol asignado',
    };
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
                    <div class="mb-4 flex flex-wrap items-center gap-2">
                        <span class="rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-white">
                            C89 Academy
                        </span>
                        <span class="rounded-full bg-[#f5b82e] px-3 py-1 text-xs font-semibold text-[#062b4f]">
                            Vista preliminar
                        </span>
                    </div>

                    <h1 class="text-3xl font-bold tracking-tight text-white md:text-4xl">
                        Hola, {{ $firstName }}
                    </h1>
                    <p class="mt-3 text-base leading-7 text-blue-100">
                        Tu espacio interno para aprender procesos, fortalecer habilidades y completar la capacitación de C89.
                    </p>

                    <div class="mt-6 inline-flex min-w-64 items-center gap-3 rounded-xl border border-white/15 bg-white/10 p-4 backdrop-blur">
                        <div class="flex size-10 items-center justify-center rounded-lg bg-white p-1.5">
                            <img src="/images/brand/c89-logo.png" alt="" class="size-full object-contain" />
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-blue-200">Organización actual</p>
                            <p class="mt-0.5 font-semibold text-white">{{ $team?->name ?? 'C89' }}</p>
                            <p class="text-sm text-blue-100">Rol: {{ $roleLabel }}</p>
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
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Datos demostrativos para validar la experiencia del producto.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <article class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Cursos asignados</p>
                    <p class="mt-2 text-3xl font-bold text-zinc-950 dark:text-white">3</p>
                </article>
                <article class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">En progreso</p>
                    <p class="mt-2 text-3xl font-bold text-[#3d8d25] dark:text-[#8bd15a]">1</p>
                </article>
                <article class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Certificados</p>
                    <p class="mt-2 text-3xl font-bold text-[#d89a12] dark:text-[#f5b82e]">0</p>
                </article>
            </div>
        </section>

        <section aria-labelledby="courses-title">
            <div class="mb-4">
                <h2 id="courses-title" class="text-xl font-semibold text-zinc-950 dark:text-white">Mis cursos</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Catálogo inicial propuesto para el piloto interno.</p>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <article class="flex flex-col rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-start justify-between gap-3">
                        <span class="rounded-full bg-[#63b32e]/15 px-2.5 py-1 text-xs font-semibold text-[#3d8d25] dark:text-[#8bd15a]">En progreso</span>
                        <span class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">40%</span>
                    </div>
                    <h3 class="mt-5 text-lg font-semibold text-zinc-950 dark:text-white">Inducción C89</h3>
                    <p class="mt-2 flex-1 text-sm leading-6 text-zinc-600 dark:text-zinc-300">Historia, cultura, herramientas y procesos esenciales para nuevos integrantes.</p>
                    <div class="mt-5 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                        <div class="h-full w-2/5 rounded-full bg-[#63b32e]"></div>
                    </div>
                    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">2 de 5 módulos propuestos</p>
                </article>

                <article class="flex flex-col rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-start justify-between gap-3">
                        <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">Pendiente</span>
                        <span class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">0%</span>
                    </div>
                    <h3 class="mt-5 text-lg font-semibold text-zinc-950 dark:text-white">Seguridad de la información</h3>
                    <p class="mt-2 flex-1 text-sm leading-6 text-zinc-600 dark:text-zinc-300">Buenas prácticas para proteger cuentas, dispositivos y datos de la empresa.</p>
                    <div class="mt-5 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"></div>
                    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">4 módulos propuestos</p>
                </article>

                <article class="flex flex-col rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-start justify-between gap-3">
                        <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">Pendiente</span>
                        <span class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">0%</span>
                    </div>
                    <h3 class="mt-5 text-lg font-semibold text-zinc-950 dark:text-white">Operación y atención al cliente</h3>
                    <p class="mt-2 flex-1 text-sm leading-6 text-zinc-600 dark:text-zinc-300">Estándares de servicio, comunicación y seguimiento para el trabajo diario.</p>
                    <div class="mt-5 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"></div>
                    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">6 módulos propuestos</p>
                </article>
            </div>
        </section>

        <aside class="rounded-xl border border-dashed border-[#63b32e]/50 bg-[#63b32e]/5 p-5 dark:border-[#63b32e]/40 dark:bg-[#63b32e]/10">
            <p class="text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                <strong class="text-zinc-950 dark:text-white">Estado del prototipo:</strong>
                la autenticación, las organizaciones y los roles ya funcionan. El catálogo, el progreso, los exámenes y los certificados se construirán en los siguientes hitos.
            </p>
        </aside>
    </div>
</x-layouts::app>
