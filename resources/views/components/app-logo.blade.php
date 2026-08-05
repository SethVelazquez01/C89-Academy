@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-9 items-center justify-center overflow-hidden rounded-lg bg-white p-1 shadow-sm">
            <img src="/images/brand/c89-logo.png" alt="" class="size-full object-contain" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-9 items-center justify-center overflow-hidden rounded-lg bg-white p-1 shadow-sm">
            <img src="/images/brand/c89-logo.png" alt="" class="size-full object-contain" />
        </x-slot>
    </flux:brand>
@endif
