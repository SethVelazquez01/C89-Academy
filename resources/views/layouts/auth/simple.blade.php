<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-[#f3f7f1] antialiased dark:bg-[#041d35]">
        <div class="relative flex min-h-svh flex-col items-center justify-center gap-6 overflow-hidden p-6 md:p-10">
            <div class="pointer-events-none absolute -left-24 top-16 size-72 rounded-full bg-[#63b32e]/15 blur-3xl"></div>
            <div class="pointer-events-none absolute -right-24 bottom-10 size-80 rounded-full bg-[#f5b82e]/10 blur-3xl"></div>
            <div class="flex w-full max-w-sm flex-col gap-2">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                    <span class="mb-2 flex size-20 items-center justify-center rounded-2xl bg-white p-2 shadow-lg shadow-[#062b4f]/10">
                        <img src="/images/brand/c89-logo.png" alt="" class="size-full object-contain" />
                    </span>
                    <span class="text-lg font-bold tracking-tight text-[#062b4f] dark:text-white">{{ config('app.name', 'C89 Academy') }}</span>
                    <span class="text-xs font-medium uppercase tracking-[0.2em] text-[#3d8d25] dark:text-[#8bd15a]">Aprender · Crecer · Certificar</span>
                </a>
                <div class="mt-4 flex flex-col gap-6 rounded-2xl border border-white/80 bg-white/90 p-6 shadow-xl shadow-[#062b4f]/10 backdrop-blur dark:border-white/10 dark:bg-[#062b4f]/80">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
