<?php

use App\Actions\Teams\CreateTeam;
use App\Actions\Teams\CancelInitialOrganizationAdministratorInvitation;
use App\Actions\Teams\DeleteOrganization;
use App\Actions\Teams\InviteInitialOrganizationAdministrator;
use App\Actions\Teams\UpdateOrganization;
use App\Data\UserTeam;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Rules\TeamName;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Teams')] class extends Component {
    public string $name = '';

    public ?int $editingOrganizationId = null;

    public string $editingOrganizationName = '';

    public ?int $deletingOrganizationId = null;

    public string $deletingOrganizationName = '';

    public ?int $administratorOrganizationId = null;

    public string $administratorOrganizationName = '';

    public string $administratorEmail = '';

    public ?int $cancelingAdministratorInvitationOrganizationId = null;

    public string $cancelingAdministratorInvitationOrganizationName = '';

    public string $cancelingAdministratorInvitationCode = '';

    public string $cancelingAdministratorInvitationEmail = '';

    public function createTeam(CreateTeam $createTeam): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', new TeamName],
        ]);

        $team = $createTeam->handle(Auth::user(), $validated['name']);

        $this->dispatch('close-modal', name: 'create-team');

        $this->reset('name');

        Flux::toast(variant: 'success', text: __('Team created.'));

        $this->redirectRoute('teams.index', navigate: true);
    }

    public function showCreateTeamModal(): void
    {
        Gate::authorize('create', Team::class);

        Flux::modal('create-team')->show();
    }

    public function showEditOrganizationModal(int $organizationId): void
    {
        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($organizationId);

        Gate::authorize('updateGlobally', $organization);

        $this->resetValidation();
        $this->editingOrganizationId = $organization->id;
        $this->editingOrganizationName = $organization->name;

        Flux::modal('edit-platform-organization')->show();
    }

    public function updateOrganization(UpdateOrganization $updateOrganization): void
    {
        $validated = $this->validate([
            'editingOrganizationName' => ['required', 'string', 'max:255', new TeamName],
        ]);

        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($this->editingOrganizationId);

        $updateOrganization->handle(
            Auth::user(),
            $organization,
            $validated['editingOrganizationName'],
        );

        Flux::modal('edit-platform-organization')->close();

        $this->reset('editingOrganizationId', 'editingOrganizationName');

        Flux::toast(variant: 'success', text: __('Organization updated.'));

        $this->redirectRoute('teams.index', navigate: true);
    }

    public function showDeleteOrganizationModal(int $organizationId): void
    {
        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($organizationId);

        Gate::authorize('deleteGlobally', $organization);

        $this->resetValidation();
        $this->deletingOrganizationId = $organization->id;
        $this->deletingOrganizationName = $organization->name;

        Flux::modal('delete-platform-organization')->show();
    }

    public function deleteOrganization(DeleteOrganization $deleteOrganization): void
    {
        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($this->deletingOrganizationId);

        $deleteOrganization->handle(Auth::user(), $organization);

        Flux::modal('delete-platform-organization')->close();

        $this->reset('deletingOrganizationId', 'deletingOrganizationName');

        Flux::toast(variant: 'success', text: __('Organization deleted.'));

        $this->redirectRoute('teams.index', navigate: true);
    }

    public function showAssignAdministratorModal(int $organizationId): void
    {
        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($organizationId);

        Gate::authorize('assignAdministratorGlobally', $organization);

        $this->resetValidation();
        $this->administratorOrganizationId = $organization->id;
        $this->administratorOrganizationName = $organization->name;
        $this->administratorEmail = '';

        Flux::modal('assign-platform-organization-administrator')->show();
    }

    public function inviteInitialAdministrator(InviteInitialOrganizationAdministrator $inviteAdministrator): void
    {
        $validated = $this->validate([
            'administratorEmail' => ['required', 'string', 'email', 'max:255'],
        ]);

        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($this->administratorOrganizationId);

        $invitation = $inviteAdministrator->handle(
            Auth::user(),
            $organization,
            $validated['administratorEmail'],
        );

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        Flux::modal('assign-platform-organization-administrator')->close();

        $this->reset('administratorOrganizationId', 'administratorOrganizationName', 'administratorEmail');

        Flux::toast(variant: 'success', text: __('Administrator invitation sent.'));

        $this->redirectRoute('teams.index', navigate: true);
    }

    public function showCancelAdministratorInvitationModal(int $organizationId): void
    {
        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($organizationId);

        Gate::authorize('cancelAdministratorInvitationGlobally', $organization);

        $invitation = $organization->invitations()
            ->where('role', TeamRole::Admin->value)
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->latest()
            ->firstOrFail();

        $this->resetValidation();
        $this->cancelingAdministratorInvitationOrganizationId = $organization->id;
        $this->cancelingAdministratorInvitationOrganizationName = $organization->name;
        $this->cancelingAdministratorInvitationCode = $invitation->code;
        $this->cancelingAdministratorInvitationEmail = $invitation->email;

        Flux::modal('cancel-platform-administrator-invitation')->show();
    }

    public function cancelInitialAdministratorInvitation(CancelInitialOrganizationAdministratorInvitation $cancelInvitation): void
    {
        $organization = Team::query()
            ->where('is_personal', false)
            ->findOrFail($this->cancelingAdministratorInvitationOrganizationId);

        $cancelInvitation->handle(
            Auth::user(),
            $organization,
            $this->cancelingAdministratorInvitationCode,
        );

        Flux::modal('cancel-platform-administrator-invitation')->close();

        $this->reset(
            'cancelingAdministratorInvitationOrganizationId',
            'cancelingAdministratorInvitationOrganizationName',
            'cancelingAdministratorInvitationCode',
            'cancelingAdministratorInvitationEmail',
        );

        Flux::toast(variant: 'success', text: __('Administrator invitation canceled.'));

        $this->redirectRoute('teams.index', navigate: true);
    }

    public function leaveTeam(int $teamId): void
    {
        $team = Team::findOrFail($teamId);
        $user = Auth::user();

        Gate::authorize('leave', $team);

        $fallbackTeam = $user->isCurrentTeam($team)
            ? $user->fallbackTeam($team)
            : null;

        $team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($fallbackTeam) {
            $user->switchTeam($fallbackTeam);
        }

        $this->dispatch('close-modal', name: "leave-team-{$teamId}");

        Flux::toast(variant: 'success', text: __('You left the team ":name"', ['name' => $team->name]));

        $this->redirectRoute('teams.index', navigate: true);
    }

    /**
     * @return Collection<int, UserTeam>
     */
    #[Computed]
    public function teams(): Collection
    {
        return Auth::user()->toUserTeams(includeCurrent: true);
    }

    /**
     * Get every real organization for the global platform owner.
     *
     * @return Collection<int, Team>
     */
    #[Computed]
    public function platformOrganizations(): Collection
    {
        Gate::authorize('create', Team::class);

        return Team::query()
            ->where('is_personal', false)
            ->withCount([
                'members',
                'members as administrators_count' => fn ($query) => $query
                    ->whereIn('team_members.role', [TeamRole::Owner->value, TeamRole::Admin->value]),
                'invitations as pending_administrator_invitations_count' => fn ($query) => $query
                    ->where('team_invitations.role', TeamRole::Admin->value)
                    ->whereNull('accepted_at')
                    ->where(fn ($pendingQuery) => $pendingQuery
                        ->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now())),
            ])
            ->orderByRaw('LOWER(name)')
            ->get();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Teams') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Teams')" :subheading="__('Manage your teams and team memberships')">
        @if (Auth::user()->isPlatformOwner())
            <div class="flex items-center justify-end">
                <flux:button
                    variant="primary"
                    icon="plus"
                    wire:click="showCreateTeamModal"
                    data-test="teams-new-team-button"
                >
                    {{ __('New team') }}
                </flux:button>
            </div>

            <div class="mt-6 space-y-3" data-test="platform-organizations">
                <div>
                    <flux:heading size="lg">{{ __('Platform organizations') }}</flux:heading>
                    <flux:text>{{ __('Organizations managed globally without granting the platform owner a membership.') }}</flux:text>
                </div>

                @forelse ($this->platformOrganizations as $organization)
                    <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="platform-organization-row">
                        <div>
                            <div class="font-medium">{{ $organization->name }}</div>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $organization->slug }}</flux:text>
                        </div>

                        <div class="flex items-center gap-2">
                            <flux:badge color="zinc">
                                {{ trans_choice(':count member|:count members', (int) $organization->getAttribute('members_count'), ['count' => (int) $organization->getAttribute('members_count')]) }}
                            </flux:badge>

                            @if ((int) $organization->getAttribute('administrators_count') > 0)
                                <flux:badge color="green">
                                    {{ trans_choice(':count administrator|:count administrators', (int) $organization->getAttribute('administrators_count'), ['count' => (int) $organization->getAttribute('administrators_count')]) }}
                                </flux:badge>
                            @elseif ((int) $organization->getAttribute('pending_administrator_invitations_count') > 0)
                                <flux:badge color="amber">{{ __('Administrator pending') }}</flux:badge>

                                <flux:tooltip :content="__('Cancel administrator invitation')">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="x-mark"
                                        wire:click="showCancelAdministratorInvitationModal({{ $organization->id }})"
                                        data-test="platform-organization-cancel-administrator-invitation"
                                    />
                                </flux:tooltip>
                            @else
                                <flux:tooltip :content="__('Assign first administrator')">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="user-plus"
                                        wire:click="showAssignAdministratorModal({{ $organization->id }})"
                                        data-test="platform-organization-assign-administrator"
                                    />
                                </flux:tooltip>
                            @endif

                            <flux:tooltip :content="__('Edit organization')">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="pencil"
                                    wire:click="showEditOrganizationModal({{ $organization->id }})"
                                    data-test="platform-organization-edit"
                                />
                            </flux:tooltip>

                            <flux:tooltip :content="__('Delete organization')">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    wire:click="showDeleteOrganizationModal({{ $organization->id }})"
                                    class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                    data-test="platform-organization-delete"
                                />
                            </flux:tooltip>
                        </div>
                    </div>
                @empty
                    <flux:text class="rounded-lg border border-dashed border-zinc-300 py-8 text-center text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        {{ __('No organizations have been created yet.') }}
                    </flux:text>
                @endforelse
            </div>
        @endif

        <div class="mt-6 space-y-3" data-test="user-team-memberships">
            @forelse ($this->teams as $team)
                <div class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="team-row">
                    <div class="flex items-center gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-medium">{{ $team->name }}</span>
                                @if ($team->isPersonal)
                                    <flux:badge color="zinc">{{ __('Personal') }}</flux:badge>
                                @endif
                            </div>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $team->roleLabel }}</flux:text>
                        </div>
                    </div>

                    <div class="flex items-center gap-1">
                        @if (! $team->isPersonal && $team->role !== 'owner')
                            <flux:modal.trigger :name="'leave-team-'.$team->id">
                                <flux:tooltip :content="__('Leave team')">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="arrow-right-start-on-rectangle"
                                        x-data=""
                                        x-on:click.prevent="$dispatch('open-modal', 'leave-team-{{ $team->id }}')"
                                        data-test="team-leave-button"
                                    />
                                </flux:tooltip>
                            </flux:modal.trigger>
                        @endif

                        <flux:tooltip :content="$team->role === 'member' ? __('View team') : __('Edit team')">
                            <flux:button
                                variant="ghost"
                                size="sm"
                                :icon="$team->role === 'member' ? 'eye' : 'pencil'"
                                :href="route('teams.edit', $team->slug)"
                                wire:navigate
                                :data-test="$team->role === 'member' ? 'team-view-button' : 'team-edit-button'"
                            />
                        </flux:tooltip>
                    </div>
                </div>

                @if (! $team->isPersonal && $team->role !== 'owner')
                    <flux:modal :name="'leave-team-'.$team->id" focusable class="max-w-lg">
                        <form wire:submit="leaveTeam({{ $team->id }})" class="space-y-6">
                            <div>
                                <flux:heading size="lg">{{ __('Leave team') }}</flux:heading>
                                <flux:subheading>
                                    {{ __('Are you sure you want to leave :name?', ['name' => $team->name]) }}
                                </flux:subheading>
                            </div>

                            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                                <flux:modal.close>
                                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                                </flux:modal.close>

                                <flux:button variant="danger" type="submit" data-test="leave-team-confirm">
                                    {{ __('Leave team') }}
                                </flux:button>
                            </div>
                        </form>
                    </flux:modal>
                @endif
            @empty
                <flux:text class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                    {{ __('You don\'t belong to any teams yet.') }}
                </flux:text>
            @endforelse
        </div>
    </x-pages::settings.layout>

    @if (Auth::user()->isPlatformOwner())
        <flux:modal name="create-team" :show="$errors->has('name')" focusable class="max-w-lg">
            <form wire:submit="createTeam" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Create a new team') }}</flux:heading>
                    <flux:subheading>{{ __('Give your team a name to get started.') }}</flux:subheading>
                </div>

                <flux:input wire:model="name" :label="__('Team name')" type="text" required autofocus data-test="create-team-name" />

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button variant="primary" type="submit" data-test="create-team-submit">
                        {{ __('Create team') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="edit-platform-organization" :show="$errors->has('editingOrganizationName')" focusable class="max-w-lg">
            <form wire:submit="updateOrganization" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Edit organization') }}</flux:heading>
                    <flux:subheading>{{ __('Update the organization name. Its slug will be regenerated automatically.') }}</flux:subheading>
                </div>

                <flux:input
                    wire:model="editingOrganizationName"
                    :label="__('Organization name')"
                    type="text"
                    required
                    autofocus
                    data-test="edit-organization-name"
                />

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button variant="primary" type="submit" data-test="edit-organization-submit">
                        {{ __('Save changes') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="delete-platform-organization" :show="$errors->has('organization')" focusable class="max-w-lg">
            <form wire:submit="deleteOrganization" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete organization') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Delete :name? Only empty organizations can be deleted.', ['name' => $deletingOrganizationName]) }}
                    </flux:subheading>
                </div>

                @error('organization')
                    <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button variant="danger" type="submit" data-test="delete-organization-confirm">
                        {{ __('Delete organization') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="assign-platform-organization-administrator" :show="$errors->has('administratorEmail')" focusable class="max-w-lg">
            <form wire:submit="inviteInitialAdministrator" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Assign first administrator') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Invite an administrator to :name. The person must accept the invitation.', ['name' => $administratorOrganizationName]) }}
                    </flux:subheading>
                </div>

                <flux:input
                    wire:model="administratorEmail"
                    :label="__('Administrator email')"
                    type="email"
                    required
                    autofocus
                    data-test="administrator-email"
                />

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button variant="primary" type="submit" data-test="administrator-invitation-submit">
                        {{ __('Send administrator invitation') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="cancel-platform-administrator-invitation" :show="$errors->has('administratorInvitation')" focusable class="max-w-lg">
            <form wire:submit="cancelInitialAdministratorInvitation" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Cancel administrator invitation') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Cancel the invitation for :email to administer :name?', [
                            'email' => $cancelingAdministratorInvitationEmail,
                            'name' => $cancelingAdministratorInvitationOrganizationName,
                        ]) }}
                    </flux:subheading>
                </div>

                @error('administratorInvitation')
                    <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Keep invitation') }}</flux:button>
                    </flux:modal.close>

                    <flux:button variant="danger" type="submit" data-test="cancel-administrator-invitation-confirm">
                        {{ __('Cancel invitation') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
