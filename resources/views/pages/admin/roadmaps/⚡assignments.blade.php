<?php

use App\Actions\Roadmaps\AssignRoadmapToCollaborator;
use App\Actions\Roadmaps\CancelRoadmapAssignment;
use App\Enums\MembershipStatus;
use App\Enums\RoadmapAssignmentStatus;
use App\Enums\RoadmapStatus;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Roadmap;
use App\Models\RoadmapAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Roadmap $roadmapModel;

    public ?int $collaboratorId = null;

    public string $dueDate = '';

    public function mount(string $roadmap): void
    {
        $team = Auth::user()->currentTeam;
        abort_if($team === null, 403);

        $this->roadmapModel = $team->roadmaps()->where('slug', $roadmap)->firstOrFail();
        Gate::authorize('assign', $this->roadmapModel);
    }

    /** @return Collection<int, Membership> */
    #[Computed]
    public function collaborators(): Collection
    {
        return Membership::query()
            ->with('user')
            ->where('team_id', $this->roadmapModel->team_id)
            ->where('role', TeamRole::Member)
            ->where('status', MembershipStatus::Active)
            ->get()
            ->sortBy(fn (Membership $membership): string => $membership->user->name)
            ->values();
    }

    /** @return Collection<int, RoadmapAssignment> */
    #[Computed]
    public function assignments(): Collection
    {
        return $this->roadmapModel->assignments()
            ->with(['user', 'assigner', 'canceller'])
            ->latest('assigned_at')
            ->get();
    }

    public function assign(AssignRoadmapToCollaborator $assignRoadmap): void
    {
        $validated = $this->validate([
            'collaboratorId' => ['required', 'integer'],
            'dueDate' => ['nullable', 'date', 'after_or_equal:today'],
        ], [
            'collaboratorId.required' => 'Selecciona un colaborador.',
            'dueDate.after_or_equal' => 'La fecha límite no puede estar en el pasado.',
        ]);

        $collaborator = User::query()->findOrFail($validated['collaboratorId']);
        $dueAt = filled($validated['dueDate']) ? CarbonImmutable::parse($validated['dueDate'])->endOfDay() : null;

        $assignRoadmap->handle(Auth::user(), $collaborator, $this->roadmapModel, $dueAt);

        $this->reset('collaboratorId', 'dueDate');
        unset($this->assignments);
        Flux::toast(variant: 'success', text: 'Roadmap asignado. Sus cursos ya están disponibles para el colaborador.');
    }

    public function cancel(int $assignmentId, CancelRoadmapAssignment $cancelRoadmap): void
    {
        $assignment = $this->roadmapModel->assignments()->whereKey($assignmentId)->firstOrFail();
        $cancelRoadmap->handle(Auth::user(), $assignment);

        unset($this->assignments);
        Flux::toast(variant: 'success', text: 'Asignación cancelada.');
    }

    public function render()
    {
        return $this->view()->title('Asignar roadmap');
    }
};
?>

<section class="mx-auto w-full max-w-5xl space-y-6">
    <header>
        <flux:button :href="route('admin.roadmaps.index')" variant="ghost" icon="arrow-left" wire:navigate>Volver a roadmaps</flux:button>
        <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div><flux:heading size="xl">Asignar roadmap</flux:heading><flux:subheading class="mt-1">{{ $roadmapModel->title }}</flux:subheading></div>
            <flux:badge :color="$roadmapModel->status === RoadmapStatus::Published ? 'green' : 'zinc'">{{ $roadmapModel->status->label() }}</flux:badge>
        </div>
    </header>

    @if ($roadmapModel->status !== RoadmapStatus::Published)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
            Publica el roadmap antes de asignarlo. <a href="{{ route('admin.roadmaps.edit', ['roadmap' => $roadmapModel]) }}" class="font-semibold underline" wire:navigate>Ir a configurarlo</a>
        </div>
    @else
        <form wire:submit="assign" class="space-y-5 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div><flux:heading size="lg">Nueva asignación</flux:heading><flux:subheading class="mt-1">El colaborador recibirá acceso a todos los cursos de esta ruta.</flux:subheading></div>
            @if ($this->collaborators->isEmpty())
                <flux:text>No hay colaboradores activos disponibles.</flux:text>
            @else
                <div class="grid gap-5 md:grid-cols-2">
                    <flux:select wire:model="collaboratorId" label="Colaborador" required data-test="roadmap-collaborator">
                        <flux:select.option value="">Selecciona un colaborador</flux:select.option>
                        @foreach ($this->collaborators as $membership)
                            <flux:select.option :value="$membership->user_id">{{ $membership->user->name }} · {{ $membership->user->email }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="dueDate" type="date" label="Fecha límite" description="Opcional" min="{{ now()->toDateString() }}" />
                </div>
                <div class="flex justify-end"><flux:button type="submit" variant="primary" icon="user-plus" data-test="roadmap-assign">Asignar roadmap</flux:button></div>
            @endif
        </form>
    @endif

    <section class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div><flux:heading size="lg">Colaboradores asignados</flux:heading><flux:subheading class="mt-1">Historial de asignaciones de esta ruta.</flux:subheading></div>
        @forelse ($this->assignments as $assignment)
            <article class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 sm:flex-row sm:items-center sm:justify-between" wire:key="roadmap-assignment-{{ $assignment->id }}">
                <div>
                    <p class="font-semibold text-zinc-950 dark:text-white">{{ $assignment->user->name }}</p>
                    <p class="mt-1 text-sm text-zinc-500">{{ $assignment->user->email }}</p>
                    <p class="mt-2 text-xs text-zinc-500">Asignado {{ $assignment->assigned_at?->format('d/m/Y') }} por {{ $assignment->assigner?->name }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge :color="$assignment->status === RoadmapAssignmentStatus::Active ? 'green' : 'zinc'">{{ $assignment->status->label() }}</flux:badge>
                    @if ($assignment->due_at)<flux:badge color="purple">Límite: {{ $assignment->due_at->format('d/m/Y') }}</flux:badge>@endif
                    @if ($assignment->status === RoadmapAssignmentStatus::Active)
                        <flux:button wire:click="cancel({{ $assignment->id }})" wire:confirm="¿Cancelar esta asignación? Se retirarán los accesos creados exclusivamente por este roadmap." variant="danger" size="sm" icon="x-circle">Cancelar</flux:button>
                    @endif
                </div>
            </article>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700"><flux:text>Todavía no se ha asignado este roadmap.</flux:text></div>
        @endforelse
    </section>
</section>
