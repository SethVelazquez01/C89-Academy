<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    @php
        $isPlatformOwner = auth()->user()->isPlatformOwner();
        $homeRoute = $isPlatformOwner ? route('platform.dashboard') : route('dashboard');
    @endphp
    <body class="min-h-screen bg-white dark:bg-[#041d35]">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-[#174667] bg-[#062b4f] dark:border-[#174667] dark:bg-[#062b4f]">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ $homeRoute }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            @if ($isPlatformOwner)
                <div class="mx-1 flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-3 py-3 text-white in-data-flux-sidebar-collapsed-desktop:justify-center in-data-flux-sidebar-collapsed-desktop:px-2">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#63b32e] text-white">
                        <flux:icon name="shield-check" class="size-4" />
                    </span>
                    <div class="min-w-0 in-data-flux-sidebar-collapsed-desktop:hidden">
                        <p class="truncate text-xs font-semibold uppercase tracking-wide text-blue-200">C89 Academy</p>
                        <p class="truncate text-sm font-semibold">Platform Owner</p>
                    </div>
                </div>
            @else
                <livewire:team-switcher />
            @endif

            <flux:sidebar.nav>
                @if ($isPlatformOwner)
                    <flux:sidebar.group heading="Plataforma" class="grid">
                        <flux:sidebar.item icon="chart-bar-square" :href="route('platform.dashboard')" :current="request()->routeIs('platform.dashboard')" wire:navigate>
                            Resumen global
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="building-office" :href="route('teams.index')" :current="request()->routeIs('teams.*')" wire:navigate>
                            Organizaciones
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @else
                    <flux:sidebar.group heading="Academia" class="grid">
                        <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                            Mi aprendizaje
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                    @can('create', \App\Models\Course::class)
                        <flux:sidebar.group heading="Administración" class="grid">
                            <flux:sidebar.item
                                icon="book-open"
                                :href="route('admin.courses.index')"
                                :current="request()->routeIs('admin.courses.*')"
                                wire:navigate
                            >
                                Cursos
                            </flux:sidebar.item>
                        </flux:sidebar.group>
                    @endcan
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" :show-team="! $isPlatformOwner" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            Configuración
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            Cerrar sesión
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @unless ($isPlatformOwner)
            <livewire:create-team-modal />
        @endunless

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
