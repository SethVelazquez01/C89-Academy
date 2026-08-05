<x-layouts::auth title="Iniciar sesión">
    <div class="flex flex-col gap-6">
        <x-auth-header title="Bienvenido a C89 Academy" description="Ingresa tu correo y contraseña para continuar" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        @if ($teamInvitation)
            <x-team-invitation-alert :invitation="$teamInvitation" action="Iniciar sesión" />
        @endif

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                label="Correo electrónico"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    label="Contraseña"
                    type="password"
                    required
                    autocomplete="current-password"
                    placeholder="Contraseña"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        ¿Olvidaste tu contraseña?
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" label="Recordarme" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    Iniciar sesión
                </flux:button>
            </div>
        </form>

        @if (Route::has('register'))
            <div class="space-x-1 text-center text-sm text-zinc-600 dark:text-zinc-400">
                <span>¿No tienes una cuenta?</span>
                <flux:link
                    :href="$teamInvitation ? route('register', ['invitation' => $teamInvitation['code']]) : route('register')"
                    data-test="register-link"
                    wire:navigate
                >
                    Crear cuenta
                </flux:link>
            </div>
        @else
            <p class="text-center text-xs leading-5 text-zinc-500 dark:text-zinc-400">
                Acceso exclusivo para personal autorizado. Solicita tu cuenta al administrador.
            </p>
        @endif
    </div>
</x-layouts::auth>
