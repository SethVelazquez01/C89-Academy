<?php

use App\Models\Course;
use App\Models\Exam;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Course $courseModel;

    public ?int $examId = null;

    public function mount(string $course): void
    {
        $team = Auth::user()->currentTeam;
        abort_if($team === null, 403);

        $this->courseModel = $team->courses()->where('slug', $course)->firstOrFail();
        Gate::authorize('update', $this->courseModel);
    }

    /** @return Collection<int, Exam> */
    #[Computed]
    public function availableExams(): Collection
    {
        return $this->courseModel->team->exams()
            ->whereDoesntHave('courses', fn ($query) => $query->whereKey($this->courseModel->id))
            ->orderBy('title')
            ->get();
    }

    /** @return Collection<int, Exam> */
    #[Computed]
    public function linkedExams(): Collection
    {
        return $this->courseModel->exams()->orderBy('title')->get();
    }

    public function attach(): void
    {
        $validated = $this->validate([
            'examId' => ['required', 'integer'],
        ], ['examId.required' => 'Selecciona un examen.']);

        Gate::authorize('update', $this->courseModel);

        $exam = $this->courseModel->team->exams()->whereKey($validated['examId'])->firstOrFail();
        $this->courseModel->exams()->syncWithoutDetaching([$exam->id]);
        $this->reset('examId');
        unset($this->availableExams, $this->linkedExams);

        Flux::toast(variant: 'success', text: 'Examen enlazado al curso.');
    }

    public function detach(int $examId): void
    {
        Gate::authorize('update', $this->courseModel);

        abort_unless($this->courseModel->exams()->whereKey($examId)->exists(), 404);

        $this->courseModel->exams()->detach($examId);
        unset($this->availableExams, $this->linkedExams);

        Flux::toast(variant: 'success', text: 'Examen retirado del curso.');
    }

    public function render()
    {
        return $this->view()->title('Exámenes del curso');
    }
};
?>

<section class="mx-auto w-full max-w-4xl space-y-6">
    <header>
        <flux:button :href="route('admin.courses.index')" variant="ghost" icon="arrow-left" wire:navigate>Volver a cursos</flux:button>
        <div class="mt-5">
            <flux:heading size="xl">Exámenes del curso</flux:heading>
            <flux:subheading class="mt-1">{{ $courseModel->title }}</flux:subheading>
        </div>
    </header>

    <form wire:submit="attach" class="space-y-5 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div>
            <flux:heading size="lg">Enlazar un examen</flux:heading>
            <flux:subheading class="mt-1">El colaborador lo verá cuando complete este curso.</flux:subheading>
        </div>
        @if ($this->availableExams->isEmpty())
            <flux:text>No hay otros exámenes disponibles.</flux:text>
        @else
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <flux:select wire:model="examId" label="Examen" class="flex-1" required data-test="course-exam-select">
                    <flux:select.option value="">Selecciona un examen</flux:select.option>
                    @foreach ($this->availableExams as $exam)
                        <flux:select.option :value="$exam->id">{{ $exam->title }} · {{ $exam->status->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button type="submit" variant="primary" icon="link" data-test="course-exam-attach">Enlazar</flux:button>
            </div>
        @endif
    </form>

    <section class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div>
            <flux:heading size="lg">Exámenes enlazados</flux:heading>
            <flux:subheading class="mt-1">Puedes retirarlos sin eliminar el examen.</flux:subheading>
        </div>
        @forelse ($this->linkedExams as $exam)
            <article class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700 sm:flex-row sm:items-center sm:justify-between" wire:key="course-exam-{{ $exam->id }}">
                <div>
                    <p class="font-semibold text-zinc-950 dark:text-white">{{ $exam->title }}</p>
                    <flux:badge class="mt-2" :color="$exam->status === \App\Enums\ExamStatus::Published ? 'green' : 'zinc'">{{ $exam->status->label() }}</flux:badge>
                </div>
                <flux:button wire:click="detach({{ $exam->id }})" wire:confirm="¿Retirar este examen del curso?" variant="danger" size="sm" icon="x-mark">Retirar</flux:button>
            </article>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700"><flux:text>Este curso todavía no tiene exámenes.</flux:text></div>
        @endforelse
    </section>
</section>
