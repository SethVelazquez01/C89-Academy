<?php

use App\Enums\RoadmapAssignmentStatus;
use App\Enums\RoadmapStatus;
use App\Models\Roadmap;
use App\Models\RoadmapAssignment;
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

    /** @return Collection<int, RoadmapAssignment> */
    #[Computed]
    public function assignments(): Collection
    {
        $user = Auth::user();
        $team = $user->currentTeam;
        abort_if($team === null, 403);

        return $user->roadmapAssignments()
            ->where('status', RoadmapAssignmentStatus::Active)
            ->whereHas('roadmap', fn ($query) => $query
                ->where('team_id', $team->id)
                ->where('status', RoadmapStatus::Published))
            ->with(['roadmap' => fn ($query) => $query->withCount('items')])
            ->latest('assigned_at')
            ->get();
    }

    public function render()
    {
        return $this->view()->title('Mis rutas');
    }
};
?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <header class="relative overflow-hidden rounded-3xl border border-[#174667] bg-[#062b4f] p-6 text-white md:p-8">
        <div class="pointer-events-none absolute -right-16 -top-20 size-64 rounded-full bg-[#63b32e]/20 blur-3xl"></div>
        <div class="relative z-10"><span class="rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-wide">Tu plan de aprendizaje</span><h1 class="mt-4 text-3xl font-bold">Mis rutas</h1><p class="mt-2 max-w-2xl text-blue-100">Aquí encontrarás los roadmaps que tu empresa te haya asignado.</p></div>
    </header>

    @if ($this->assignments->isEmpty())
        <div class="rounded-2xl border border-dashed border-zinc-300 bg-white px-6 py-16 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <flux:icon name="map" class="mx-auto size-9 text-[#3d8d25]" />
            <flux:heading size="lg" class="mt-4">Aún no tienes rutas asignadas</flux:heading>
            <flux:text class="mx-auto mt-2 max-w-xl">Cuando tu administrador te asigne un roadmap, aparecerá aquí. No necesitas solicitarlo ni configurarlo.</flux:text>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->assignments as $assignment)
                <article class="flex flex-col rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" wire:key="my-roadmap-{{ $assignment->id }}">
                    <div class="flex items-start justify-between gap-3"><flux:badge color="green">Asignado</flux:badge>@if ($assignment->due_at)<flux:badge color="purple">Hasta {{ $assignment->due_at->format('d/m/Y') }}</flux:badge>@endif</div>
                    <flux:heading size="lg" class="mt-5">{{ $assignment->roadmap->title }}</flux:heading>
                    <flux:text class="mt-2 flex-1">{{ $assignment->roadmap->summary ?? 'Sigue los pasos en el orden indicado.' }}</flux:text>
                    <p class="mt-4 text-sm font-medium text-zinc-500">{{ $assignment->roadmap->items_count }} pasos</p>
                    <flux:button :href="route('roadmaps.show', ['roadmap' => $assignment->roadmap])" variant="primary" class="mt-4 w-full" icon="arrow-right" wire:navigate>Ver mi ruta</flux:button>
                </article>
            @endforeach
        </div>
    @endif
</section>
