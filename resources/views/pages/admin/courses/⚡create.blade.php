<?php

use App\Enums\CourseStatus;
use App\Models\Course;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public string $title = '';

    public string $summary = '';

    public string $description = '';

    public ?int $estimatedDurationMinutes = null;

    public function mount(): void
    {
        Gate::authorize('create', Course::class);
    }

    public function save(): void
    {
        Gate::authorize('create', Course::class);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'estimatedDurationMinutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
        ]);

        $user = Auth::user();
        $team = $user->currentTeam;

        abort_if($team === null, 403);

        Course::query()->create([
            'team_id' => $team->id,
            'created_by' => $user->id,
            'title' => $validated['title'],
            'summary' => filled($validated['summary']) ? $validated['summary'] : null,
            'description' => filled($validated['description']) ? $validated['description'] : null,
            'estimated_duration_minutes' => $validated['estimatedDurationMinutes'],
            'status' => CourseStatus::Draft,
        ]);

        Flux::toast(variant: 'success', text: 'Curso guardado como borrador.');

        $this->redirectRoute(
            'admin.courses.index',
            ['current_team' => $team],
            navigate: true,
        );
    }

    public function render()
    {
        return $this->view()->title('Crear curso');
    }
};
?>

<section class="mx-auto w-full max-w-3xl space-y-6">
    <header>
        <flux:button :href="route('admin.courses.index')" variant="ghost" icon="arrow-left" wire:navigate>
            Volver a cursos
        </flux:button>

        <div class="mt-5">
            <flux:heading size="xl">Crear curso</flux:heading>
            <flux:subheading class="mt-1">Completa la información principal. El curso se guardará inicialmente como borrador.</flux:subheading>
        </div>
    </header>

    <form wire:submit="save" class="space-y-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 md:p-8">
        <flux:input
            wire:model="title"
            label="Título del curso"
            placeholder="Ej. Inducción C89"
            required
            autofocus
            data-test="course-title-input"
        />

        <flux:textarea
            wire:model="summary"
            label="Resumen corto"
            description="Una explicación breve que aparecerá en la tarjeta del curso."
            placeholder="Describe en una o dos frases qué aprenderá el colaborador."
            rows="3"
            data-test="course-summary-input"
        />

        <flux:textarea
            wire:model="description"
            label="Descripción"
            placeholder="Explica el objetivo y alcance general del curso."
            rows="6"
            data-test="course-description-input"
        />

        <flux:input
            wire:model.number="estimatedDurationMinutes"
            label="Duración estimada en minutos"
            type="number"
            min="1"
            max="10080"
            placeholder="60"
            data-test="course-duration-input"
        />

        <div class="flex flex-col-reverse gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700 sm:flex-row sm:justify-end">
            <flux:button :href="route('admin.courses.index')" variant="ghost" wire:navigate>
                Cancelar
            </flux:button>
            <flux:button variant="primary" type="submit" icon="document-check" data-test="course-save-button">
                Guardar borrador
            </flux:button>
        </div>
    </form>
</section>
