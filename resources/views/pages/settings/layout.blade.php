@props([
    'heading' => '',
    'subheading' => '',
    'fullWidth' => false,
])

<div @class(['flex items-start max-md:flex-col', 'w-full' => $fullWidth])>
    @unless ($fullWidth)
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('Security') }}</flux:navlist.item>
            <flux:navlist.item :href="route('teams.index')" :current="request()->routeIs('teams.*')" wire:navigate>{{ __('Teams') }}</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />
    @endunless

    <div @class(['flex-1 self-stretch', 'max-md:pt-6' => ! $fullWidth])>
        @unless ($fullWidth)
            <flux:heading>{{ $heading }}</flux:heading>
            <flux:subheading>{{ $subheading }}</flux:subheading>
        @endunless

        <div @class(['w-full', 'mt-5 max-w-lg' => ! $fullWidth])>
            {{ $slot }}
        </div>
    </div>
</div>
