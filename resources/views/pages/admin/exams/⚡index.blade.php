<?php

use App\Enums\ExamStatus;
use App\Models\Exam;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $showTrash = false;

    public function mount(): void
    {
        Gate::authorize('create', Exam::class);
    }

    /** @return Collection<int, Exam> */
    #[Computed]
    public function exams(): Collection
    {
        $team = Auth::user()->currentTeam;

        abort_if($team === null, 403);

        $query = $team->exams()->withCount(['questions', 'attempts']);

        if ($this->showTrash) {
            $query->onlyTrashed();
        }

        return $query->latest()->get();
    }

    public function showActiveExams(): void
    {
        $this->showTrash = false;
        unset($this->exams);
    }

    public function showTrashedExams(): void
    {
        $this->showTrash = true;
        unset($this->exams);
    }

    public function restore(int $examId): void
    {
        $team = Auth::user()->currentTeam;

        abort_if($team === null, 403);

        $exam = $team->exams()->onlyTrashed()->whereKey($examId)->firstOrFail();

        Gate::authorize('restore', $exam);

        $exam->restore();
        $exam->update(['status' => ExamStatus::Draft, 'published_at' => null]);

        unset($this->exams);

        Flux::toast(variant: 'success', text: 'Examen restaurado como borrador.');
    }

    public function render()
    {
        return $this->view()->title('Administrar exámenes');
    }
};
?>

<section class="mx-auto w-full max-w-7xl space-y-6" data-test="admin-exams-index">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">Exámenes</flux:heading>
            <flux:subheading class="mt-1">Diseña evaluaciones independientes y listas para conectarse a futuros roadmaps.</flux:subheading>
        </div>
        <flux:button :href="route('admin.exams.create')" variant="primary" icon="plus" wire:navigate>Crear examen</flux:button>
    </header>

    <div class="grid gap-4 md:grid-cols-3">
        <article class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"><p class="text-sm text-zinc-500">Exámenes activos</p><p class="mt-2 text-3xl font-bold">{{ $this->exams->count() }}</p></article>
        <article class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"><p class="text-sm text-zinc-500">Preguntas configuradas</p><p class="mt-2 text-3xl font-bold text-[#3d8d25]">{{ $this->exams->sum('questions_count') }}</p></article>
        <article class="rounded-2xl border border-[#174667] bg-[#062b4f] p-5 text-white"><p class="text-sm text-blue-200">Arquitectura</p><p class="mt-2 font-semibold">Preparada para ser un paso final de roadmap</p></article>
    </div>

    <div class="flex gap-2 border-b border-zinc-200 pb-3 dark:border-zinc-700">
        <flux:button wire:click="showActiveExams" :variant="$showTrash ? 'ghost' : 'primary'" size="sm">Activos</flux:button>
        <flux:button wire:click="showTrashedExams" :variant="$showTrash ? 'primary' : 'ghost'" size="sm">Papelera</flux:button>
    </div>

    @if ($this->exams->isEmpty())
        <div class="rounded-2xl border border-dashed border-zinc-300 bg-white px-6 py-16 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-[#63b32e]/15 text-[#3d8d25]"><flux:icon name="clipboard-document-check" class="size-7" /></div>
            <flux:heading size="lg" class="mt-5">{{ $showTrash ? 'La papelera está vacía' : 'Todavía no hay exámenes' }}</flux:heading>
            <flux:text class="mx-auto mt-2 max-w-lg">{{ $showTrash ? 'Los exámenes eliminados aparecerán aquí.' : 'Crea el primer borrador y configura sus preguntas y controles de seguridad.' }}</flux:text>
            @unless ($showTrash)<flux:button :href="route('admin.exams.create')" variant="primary" class="mt-6" wire:navigate>Crear el primer examen</flux:button>@endunless
        </div>
    @else
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($this->exams as $exam)
                <article class="flex flex-col rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" wire:key="exam-{{ $exam->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <flux:badge :color="$showTrash ? 'red' : ($exam->status === ExamStatus::Published ? 'green' : 'zinc')">{{ $showTrash ? 'Eliminado' : $exam->status->label() }}</flux:badge>
                        <span class="text-xs text-zinc-500">{{ $exam->time_limit_minutes }} min</span>
                    </div>
                    <flux:heading size="lg" class="mt-4">{{ $exam->title }}</flux:heading>
                    <flux:text class="mt-2 line-clamp-2">{{ $exam->summary ?? 'Sin resumen' }}</flux:text>
                    <div class="mt-4 flex flex-wrap gap-2"><flux:badge color="blue">{{ $exam->questions_count }} preguntas</flux:badge><flux:badge color="amber">Aprobación {{ $exam->passing_score }}%</flux:badge>@unless ($showTrash)<flux:badge color="purple">{{ $exam->attempts_count }} intentos</flux:badge>@endunless</div>
                    <div class="mt-5 flex flex-wrap items-center justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        @if ($showTrash)
                            <flux:button wire:click="restore({{ $exam->id }})" wire:confirm="¿Restaurar este examen como borrador?" variant="outline" size="sm" icon="arrow-path">Restaurar</flux:button>
                        @else
                            <flux:button :href="route('admin.exams.attempts', ['exam' => $exam])" variant="ghost" size="sm" icon="users" wire:navigate>Intentos</flux:button>
                            <flux:button :href="route('admin.exams.edit', ['exam' => $exam])" variant="ghost" size="sm" icon="pencil-square" wire:navigate>Configurar</flux:button>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
