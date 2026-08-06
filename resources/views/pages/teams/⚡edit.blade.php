<?php

use App\Actions\Teams\ChangeMembershipRole;
use App\Actions\Teams\ChangeMembershipStatus;
use App\Data\TeamPermissions;
use App\Enums\MembershipStatus;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Rules\TeamName;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public string $teamName = '';

    public array $teamData = [];

    public array $members = [];

    public array $invitations = [];

    public array $availableRoles = [];

    public bool $isCurrentTeam = false;

    public function mount(Team $team): void
    {
        $this->teamModel = $team;
        $this->teamName = $team->name;

        $this->populateTeamData();
    }

    public function updateTeam(): void
    {
        Gate::authorize('update', $this->teamModel);

        $validated = $this->validate([
            'teamName' => ['required', 'string', 'max:255', new TeamName],
        ]);

        $team = DB::transaction(function () use ($validated) {
            $team = Team::whereKey($this->teamModel->id)->lockForUpdate()->firstOrFail();

            $team->update(['name' => $validated['teamName']]);

            return $team;
        });

        $this->teamModel = $team;

        $this->populateTeamData();

        Flux::toast(variant: 'success', text: __('Team updated.'));

        $this->redirectRoute('teams.edit', ['team' => $this->teamModel->fresh()->slug], navigate: true);
    }

    public function updateMember(int $userId, string $role, ChangeMembershipRole $changeMembershipRole): void
    {
        $member = User::findOrFail($userId);

        $changeMembershipRole->handle(
            Auth::user(),
            $this->teamModel,
            $member,
            $role,
        );

        $this->populateTeamData();

        Flux::toast(variant: 'success', text: __('Member role updated.'));
    }

    public function suspendMember(int $userId, ChangeMembershipStatus $changeMembershipStatus): void
    {
        $member = User::findOrFail($userId);

        $changeMembershipStatus->handle(
            Auth::user(),
            $this->teamModel,
            $member,
            MembershipStatus::Suspended,
        );

        $this->populateTeamData();

        Flux::toast(variant: 'success', text: __('Member suspended.'));
    }

    public function reactivateMember(int $userId, ChangeMembershipStatus $changeMembershipStatus): void
    {
        $member = User::findOrFail($userId);

        $changeMembershipStatus->handle(
            Auth::user(),
            $this->teamModel,
            $member,
            MembershipStatus::Active,
        );

        $this->populateTeamData();

        Flux::toast(variant: 'success', text: __('Member reactivated.'));
    }

    private function populateTeamData(): void
    {
        $user = Auth::user();

        $team = $this->teamModel->fresh();

        $this->teamData = [
            'id' => $team->id,
            'name' => $team->name,
            'slug' => $team->slug,
            'is_personal' => $team->is_personal,
        ];

        $this->members = $team->members()->get()->map(fn ($member) => [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'avatar' => $member->avatar ?? null,
            'initials' => $member->initials(),
            'role' => $member->pivot->role->value,
            'role_label' => $member->pivot->role->label(),
            'status' => $member->pivot->status->value,
            'status_label' => $member->pivot->status->label(),
            'is_self' => $member->id === $user->id,
        ])->toArray();

        $this->invitations = $team->invitations()
            ->whereNull('accepted_at')
            ->get()
            ->map(fn ($invitation) => [
                'code' => $invitation->code,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'role_label' => $invitation->role->label(),
                'created_at' => $invitation->created_at->toISOString(),
            ])->toArray();

        $this->availableRoles = TeamRole::assignable();

        $this->isCurrentTeam = $user->isCurrentTeam($team);
    }

    public function render()
    {
        $teamName = $this->teamData['name'] ?? $this->teamModel->name;

        $title = $this->permissions->canUpdateTeam
            ? __('Edit :name', ['name' => $teamName])
            : __('View :name', ['name' => $teamName]);

        return $this->view()->title($title);
    }

    #[Computed]
    public function permissions(): TeamPermissions
    {
        return Auth::user()->toTeamPermissions($this->teamModel);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Teams') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Teams')" :subheading="__('Manage your team settings')">
        <div class="space-y-10">
            <div class="space-y-6">
                @if ($this->permissions->canUpdateTeam)
                    <div class="space-y-4">
                        <form wire:submit="updateTeam" class="space-y-6">
                            <flux:input wire:model="teamName" :label="__('Team name')" required data-test="team-name-input" />

                            <flux:button variant="primary" type="submit" data-test="team-save-button">
                                {{ __('Save') }}
                            </flux:button>
                        </form>
                    </div>
                @else
                    <div>
                        <flux:heading>{{ $teamData['name'] }}</flux:heading>
                    </div>
                @endif
            </div>

            <div class="space-y-6">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:heading>{{ __('Team members') }}</flux:heading>
                        @if ($this->permissions->canAddMember || $this->permissions->canUpdateMember || $this->permissions->canRemoveMember)
                            <flux:subheading>{{ __('Manage who belongs to this team') }}</flux:subheading>
                        @endif
                    </div>

                    @if ($this->permissions->canCreateInvitation)
                        <flux:modal.trigger name="invite-member">
                            <flux:button variant="primary" icon="user-plus" data-test="invite-member-button">
                                {{ __('Invite member') }}
                            </flux:button>
                        </flux:modal.trigger>
                    @endif
                </div>

                <div class="space-y-3">
                    @foreach ($members as $member)
                        <div
                            @class([
                                'flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900',
                                'opacity-70' => $member['status'] === 'suspended',
                            ])
                            data-test="member-row"
                        >
                            <div class="flex items-center gap-4">
                                <flux:avatar :name="$member['name']" :initials="$member['initials']" />
                                <div>
                                    <div class="font-medium">{{ $member['name'] }}</div>
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $member['email'] }}</flux:text>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <flux:badge :color="$member['status'] === 'active' ? 'green' : 'amber'" data-test="member-status-badge">
                                    {{ $member['status_label'] }}
                                </flux:badge>

                                @if ($member['role'] !== 'owner' && ! $member['is_self'] && $this->permissions->canUpdateMember)
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button variant="outline" size="sm" icon:trailing="chevron-down" data-test="member-role-trigger">
                                            {{ $member['role_label'] }}
                                        </flux:button>
                                        <flux:menu>
                                            @foreach ($availableRoles as $role)
                                                <flux:menu.item
                                                    as="button"
                                                    type="button"
                                                    wire:click="updateMember({{ $member['id'] }}, '{{ $role['value'] }}')"
                                                    data-test="member-role-option"
                                                >
                                                    {{ $role['label'] }}
                                                </flux:menu.item>
                                            @endforeach
                                        </flux:menu>
                                    </flux:dropdown>
                                @else
                                    <flux:badge color="zinc">{{ $member['role_label'] }}</flux:badge>
                                @endif

                                @if ($member['role'] !== 'owner' && ! $member['is_self'] && $this->permissions->canManageMemberStatus)
                                    @if ($member['status'] === 'active')
                                        <flux:modal.trigger name="suspend-member-{{ $member['id'] }}">
                                            <flux:tooltip :content="__('Suspend member')">
                                                <flux:button
                                                    variant="ghost"
                                                    size="sm"
                                                    icon="pause-circle"
                                                    data-test="member-suspend-button"
                                                />
                                            </flux:tooltip>
                                        </flux:modal.trigger>
                                    @elseif ($member['status'] === 'suspended')
                                        <flux:tooltip :content="__('Reactivate member')">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="play-circle"
                                                wire:click="reactivateMember({{ $member['id'] }})"
                                                data-test="member-reactivate-button"
                                            />
                                        </flux:tooltip>
                                    @endif
                                @endif

                                @if ($member['role'] !== 'owner' && ! $member['is_self'] && $this->permissions->canRemoveMember)
                                    <flux:modal.trigger name="remove-member-{{ $member['id'] }}">
                                        <flux:tooltip :content="__('Remove member')">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="x-mark"
                                                data-test="member-remove-button"
                                            />
                                        </flux:tooltip>
                                    </flux:modal.trigger>
                                @endif
                            </div>
                        </div>

                        @if ($member['role'] !== 'owner' && ! $member['is_self'] && $this->permissions->canManageMemberStatus && $member['status'] === 'active')
                            <flux:modal name="suspend-member-{{ $member['id'] }}" focusable class="max-w-lg">
                                <form wire:submit="suspendMember({{ $member['id'] }})" class="space-y-6">
                                    <div>
                                        <flux:heading size="lg">{{ __('Suspend member') }}</flux:heading>
                                        <flux:subheading>
                                            {{ __('Suspend :name? They will lose access to this organization until reactivated.', ['name' => $member['name']]) }}
                                        </flux:subheading>
                                    </div>

                                    <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                                        <flux:modal.close>
                                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                                        </flux:modal.close>

                                        <flux:button variant="danger" type="submit" data-test="member-suspend-confirm">
                                            {{ __('Suspend member') }}
                                        </flux:button>
                                    </div>
                                </form>
                            </flux:modal>
                        @endif

                        @if ($member['role'] !== 'owner' && ! $member['is_self'] && $this->permissions->canRemoveMember)
                            <livewire:pages::teams.remove-member-modal
                                :team="$teamModel"
                                :member-id="$member['id']"
                                :member-name="$member['name']"
                                :modal-name="'remove-member-'.$member['id']"
                                :key="'remove-member-modal-'.$member['id']"
                            />
                        @endif
                    @endforeach
                </div>
            </div>

            @if (count($invitations) > 0)
                <div class="space-y-6">
                    <div>
                        <flux:heading>{{ __('Pending invitations') }}</flux:heading>
                        <flux:subheading>{{ __('Invitations that have not been accepted yet') }}</flux:subheading>
                    </div>

                    <div class="space-y-3">
                        @foreach ($invitations as $invitation)
                            <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="invitation-row">
                                <div class="flex items-center gap-4">
                                    <div class="flex size-10 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                        <flux:icon name="envelope" class="text-zinc-500" />
                                    </div>
                                    <div>
                                        <div class="font-medium">{{ $invitation['email'] }}</div>
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $invitation['role_label'] }}</flux:text>
                                    </div>
                                </div>

                                @if ($this->permissions->canCancelInvitation)
                                    <flux:modal.trigger name="cancel-invitation-{{ $invitation['code'] }}">
                                        <flux:tooltip :content="__('Cancel invitation')">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="x-mark"
                                                data-test="invitation-cancel-button"
                                            />
                                        </flux:tooltip>
                                    </flux:modal.trigger>
                                @endif
                            </div>
                            @if ($this->permissions->canCancelInvitation)
                                <livewire:pages::teams.cancel-invitation-modal
                                    :team="$teamModel"
                                    :invitation-code="$invitation['code']"
                                    :invitation-email="$invitation['email']"
                                    :modal-name="'cancel-invitation-'.$invitation['code']"
                                    :key="'cancel-invitation-modal-'.$invitation['code']"
                                />
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($this->permissions->canDeleteTeam && ! $teamData['is_personal'])
                <div class="space-y-6">
                    <div>
                        <flux:heading>{{ __('Delete team') }}</flux:heading>
                        <flux:subheading>{{ __('Permanently delete your team') }}</flux:subheading>
                    </div>

                    <div class="space-y-4 rounded-lg border border-red-200 bg-red-50 p-4 text-red-700 dark:border-red-200/10 dark:bg-red-900/20 dark:text-red-100">
                        <div>
                            <p class="font-medium">{{ __('Warning') }}</p>
                            <p class="text-sm">{{ __('Please proceed with caution, this cannot be undone.') }}</p>
                        </div>

                        <flux:modal.trigger name="delete-team">
                            <flux:button variant="danger" data-test="delete-team-button">
                                {{ __('Delete team') }}
                            </flux:button>
                        </flux:modal.trigger>
                    </div>
                </div>
            @endif
        </div>
    </x-pages::settings.layout>

    @if ($this->permissions->canCreateInvitation)
        <livewire:pages::teams.invite-member-modal :team="$teamModel" />
    @endif

    @if ($this->permissions->canDeleteTeam && ! $teamData['is_personal'])
        <livewire:pages::teams.delete-team-modal :team="$teamModel" />
    @endif
</section>
