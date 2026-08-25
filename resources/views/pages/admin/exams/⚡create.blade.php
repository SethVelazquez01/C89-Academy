<?php

use App\Enums\ExamStatus;
use App\Models\Exam;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public string $title = '';

    public string $summary = '';

    public function mount(): void
    {
        Gate::authorize('create', Exam::class);
    }

    public function save(): void
    {
        Gate::authorize('create', Exam::class);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
        ]);

        $user = Auth::user();
        $team = $user->currentTeam;

        abort_if($team === null, 403);

        $exam = Exam::query()->create([
            'team_id' => $team->id,
            'created_by' => $user->id,
            'title' => $validated['title'],
            'summary' => filled($validated['summary']) ? $validated['summary'] : null,
            'status' => ExamStatus::Draft,
        ]);

        Flux::toast(variant: 'success', text: 'Examen creado. Ahora agrega sus preguntas.');
        $this->redirectRoute('admin.exams.edit', ['current_team' => $team, 'exam' => $exam], navigate: true);
    }
};
?>

<section class="mx-auto w-full max-w-3xl space-y-6">
    <header>
        <flux:button :href="route('admin.exams.index')" variant="ghost" icon="arrow-left" wire:navigate>Volver a exámenes</flux:button>
        <div class="mt-5">
            <flux:heading size="xl">Crear examen</flux:heading>
            <flux:subheading class="mt-1">Ponle un nombre. Las preguntas y reglas se configuran en el siguiente paso.</flux:subheading>
        </div>
    </header>

    <form wire:submit="save" class="space-y-6 rounded-2xl border border-zinc-200 border-t-4 border-t-[#63b32e] bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 md:p-8">
        <flux:input
            wire:model="title"
            label="Título del examen"
            placeholder="Ej. Certificación Inspector nivel 1"
            required
            autofocus
            data-test="exam-title-input"
        />

        <flux:textarea
            wire:model="summary"
            label="Descripción (opcional)"
            rows="3"
            placeholder="Explica brevemente qué competencia acredita."
        />

        <div class="flex flex-col-reverse gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700 sm:flex-row sm:justify-end">
            <flux:button :href="route('admin.exams.index')" variant="ghost" wire:navigate>Cancelar</flux:button>
            <flux:button variant="primary" type="submit" icon="arrow-right" data-test="exam-save-button">
                Crear y agregar preguntas
            </flux:button>
        </div>
    </form>
</section>
