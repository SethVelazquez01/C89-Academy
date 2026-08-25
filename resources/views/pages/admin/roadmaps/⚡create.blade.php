<?php

use App\Enums\RoadmapStatus;
use App\Models\Roadmap;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public string $title = '';

    public string $summary = '';

    public function mount(): void
    {
        Gate::authorize('create', Roadmap::class);
    }

    public function save(): void
    {
        Gate::authorize('create', Roadmap::class);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = Auth::user();
        $team = $user->currentTeam;
        abort_if($team === null, 403);

        $roadmap = Roadmap::query()->create([
            'team_id' => $team->id,
            'created_by' => $user->id,
            'title' => $validated['title'],
            'summary' => filled($validated['summary']) ? $validated['summary'] : null,
            'status' => RoadmapStatus::Draft,
        ]);

        $this->redirectRoute('admin.roadmaps.edit', ['current_team' => $team, 'roadmap' => $roadmap], navigate: true);
    }

    public function render()
    {
        return $this->view()->title('Crear roadmap');
    }
};
?>

<section class="mx-auto w-full max-w-3xl space-y-6">
    <header>
        <flux:button :href="route('admin.roadmaps.index')" variant="ghost" icon="arrow-left" wire:navigate>Volver a roadmaps</flux:button>
        <div class="mt-5">
            <flux:heading size="xl">Crear roadmap</flux:heading>
            <flux:subheading class="mt-1">Primero dale un nombre. En la siguiente pantalla agregarás cursos y exámenes.</flux:subheading>
        </div>
    </header>

    <form wire:submit="save" class="space-y-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 md:p-8">
        <flux:input wire:model="title" label="Nombre del roadmap" placeholder="Ej. Inspector nivel 1" required autofocus data-test="roadmap-title" />
        <flux:textarea wire:model="summary" label="Descripción breve" placeholder="Explica para quién es y qué logrará." rows="4" data-test="roadmap-summary" />
        <div class="flex flex-col-reverse gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700 sm:flex-row sm:justify-end">
            <flux:button :href="route('admin.roadmaps.index')" variant="ghost" wire:navigate>Cancelar</flux:button>
            <flux:button type="submit" variant="primary" icon="arrow-right" data-test="roadmap-save">Crear y agregar pasos</flux:button>
        </div>
    </form>
</section>
