<?php

use App\Enums\RoadmapStatus;
use App\Models\Roadmap;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public function mount(): void
    {
        Gate::authorize('viewAny', Roadmap::class);
    }

    /** @return Collection<int, Roadmap> */
    #[Computed]
    public function roadmaps(): Collection
    {
        $team = Auth::user()->currentTeam;
        abort_if($team === null, 403);

        return $team->roadmaps()
            ->withCount(['items', 'assignments as active_assignments_count' => fn ($query) => $query->where('status', \App\Enums\RoadmapAssignmentStatus::Active)])
            ->latest()
            ->get();
    }

    public function render()
    {
        return $this->view()->title('Roadmaps');
    }
};
?>

<section class="mx-auto w-full max-w-7xl space-y-6">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">Roadmaps</flux:heading>
            <flux:subheading class="mt-1">Organiza cursos y exámenes en una ruta clara para cada colaborador.</flux:subheading>
        </div>
        <flux:button :href="route('admin.roadmaps.create')" variant="primary" icon="plus" wire:navigate>Crear roadmap</flux:button>
    </header>

    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-100">
        Crea la ruta, agrega sus pasos, publícala y finalmente asígnala. Los colaboradores no pueden elegir roadmaps por su cuenta.
    </div>

    @if ($this->roadmaps->isEmpty())
        <div class="rounded-2xl border border-dashed border-zinc-300 bg-white px-6 py-16 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <flux:icon name="map" class="mx-auto size-9 text-[#3d8d25]" />
            <flux:heading size="lg" class="mt-4">Todavía no hay roadmaps</flux:heading>
            <flux:text class="mx-auto mt-2 max-w-lg">Empieza con una ruta sencilla y agrega los cursos en el orden que deben completarse.</flux:text>
            <flux:button :href="route('admin.roadmaps.create')" variant="primary" class="mt-6" wire:navigate>Crear el primero</flux:button>
        </div>
    @else
        <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->roadmaps as $roadmap)
                <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" wire:key="roadmap-{{ $roadmap->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <flux:badge :color="$roadmap->status === RoadmapStatus::Published ? 'green' : ($roadmap->status === RoadmapStatus::Archived ? 'amber' : 'zinc')">{{ $roadmap->status->label() }}</flux:badge>
                        <span class="text-xs text-zinc-500">{{ $roadmap->created_at?->format('d/m/Y') }}</span>
                    </div>
                    <flux:heading size="lg" class="mt-4">{{ $roadmap->title }}</flux:heading>
                    <flux:text class="mt-2 line-clamp-2">{{ $roadmap->summary ?? 'Sin descripción' }}</flux:text>
                    <div class="mt-4 flex gap-2">
                        <flux:badge color="blue">{{ $roadmap->items_count }} pasos</flux:badge>
                        <flux:badge color="purple">{{ $roadmap->active_assignments_count }} asignados</flux:badge>
                    </div>
                    <div class="mt-5 flex flex-wrap justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        <flux:button :href="route('admin.roadmaps.assignments', ['roadmap' => $roadmap])" variant="ghost" size="sm" icon="user-plus" wire:navigate>Asignar</flux:button>
                        <flux:button :href="route('admin.roadmaps.edit', ['roadmap' => $roadmap])" variant="primary" size="sm" icon="pencil-square" wire:navigate>Configurar</flux:button>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
