<?php

use App\Actions\Exams\ReviewExamAnswer;
use App\Enums\ExamAttemptStatus;
use App\Enums\ExamQuestionType;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptEvent;
use App\Models\ExamAttemptQuestion;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Exam $examModel;

    public ExamAttempt $attempt;

    /** @var array<int, int|null> */
    public array $awardedPoints = [];

    /** @var array<int, string> */
    public array $reviewComments = [];

    public function mount(string $exam, string $examAttempt): void
    {
        $team = Auth::user()->currentTeam;
        abort_if($team === null, 403);

        $this->examModel = $team->exams()->where('slug', $exam)->firstOrFail();
        $this->attempt = $this->examModel->attempts()
            ->where('uuid', $examAttempt)
            ->with('user')
            ->firstOrFail();
        Gate::authorize('review', $this->attempt);

        foreach ($this->attempt->questions()->where('type', ExamQuestionType::ShortAnswer)->get() as $question) {
            $this->awardedPoints[$question->id] = $question->awarded_points;
            $this->reviewComments[$question->id] = $question->review_comment ?? '';
        }
    }

    /** @return Collection<int, ExamAttemptQuestion> */
    #[Computed]
    public function questions(): Collection
    {
        return $this->attempt->questions()->with('reviewer')->get();
    }

    /** @return Collection<int, ExamAttemptEvent> */
    #[Computed]
    public function events(): Collection
    {
        return $this->attempt->events()->get();
    }

    public function saveReview(int $questionId, ReviewExamAnswer $reviewAnswer): void
    {
        $question = $this->attempt->questions()
            ->where('type', ExamQuestionType::ShortAnswer)
            ->whereKey($questionId)
            ->firstOrFail();

        $validated = $this->validate([
            "awardedPoints.{$questionId}" => ['required', 'integer', 'min:0', 'max:'.$question->points],
            "reviewComments.{$questionId}" => ['nullable', 'string', 'max:2000'],
        ]);

        $reviewAnswer->handle(
            Auth::user(),
            $question,
            (int) $validated['awardedPoints'][$questionId],
            $validated['reviewComments'][$questionId] ?? null,
        );

        $this->attempt->refresh();
        unset($this->questions);

        Flux::toast(
            variant: 'success',
            text: $this->attempt->status->isFinal()
                ? 'Revisión terminada. El resultado final ya está disponible.'
                : 'Respuesta calificada.',
        );
    }

    public function render()
    {
        return $this->view()->title('Revisar intento');
    }
};
?>

<section class="mx-auto w-full max-w-5xl space-y-6">
    <header>
        <flux:button :href="route('admin.exams.attempts', ['exam' => $examModel])" variant="ghost" icon="arrow-left" wire:navigate>Volver a intentos</flux:button>
        <div class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div><flux:heading size="xl">Revisar intento</flux:heading><flux:subheading class="mt-1">{{ $attempt->user->name }} · {{ $examModel->title }} · Intento {{ $attempt->attempt_number }}</flux:subheading></div>
            <flux:badge :color="$attempt->status->color()" size="lg">{{ $attempt->status->label() }}</flux:badge>
        </div>
    </header>

    <section class="grid gap-4 sm:grid-cols-4">
        <article class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"><p class="text-sm text-zinc-500">Resultado</p><p class="mt-2 text-2xl font-bold">{{ $attempt->percentage_score !== null ? number_format($attempt->percentage_score, 0).'%' : 'Pendiente' }}</p></article>
        <article class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"><p class="text-sm text-zinc-500">Puntos actuales</p><p class="mt-2 text-2xl font-bold">{{ $attempt->earned_points ?? 0 }} / {{ $attempt->max_points }}</p></article>
        <article class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"><p class="text-sm text-zinc-500">Tiempo usado</p><p class="mt-2 text-2xl font-bold">{{ $attempt->submitted_at ? $attempt->started_at->diffForHumans($attempt->submitted_at, true) : 'En curso' }}</p></article>
        <article class="rounded-2xl border {{ $attempt->security_event_count > 0 ? 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30' : 'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900' }} p-5"><p class="text-sm text-zinc-500">Alertas</p><p class="mt-2 text-2xl font-bold">{{ $attempt->security_event_count }}</p></article>
    </section>

    @if ($attempt->timed_out)<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-100">El sistema entregó este intento al terminar el tiempo.</div>@endif

    <section class="space-y-4">
        <div><flux:heading size="lg">Respuestas</flux:heading><flux:subheading class="mt-1">Las preguntas cerradas ya están calificadas. Revisa las respuestas abiertas.</flux:subheading></div>
        @foreach ($this->questions as $question)
            <article class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" wire:key="review-question-{{ $question->id }}">
                <div class="flex flex-wrap items-center gap-2"><span class="flex size-7 items-center justify-center rounded-full bg-zinc-100 text-xs font-bold dark:bg-zinc-800">{{ $question->position }}</span><flux:badge color="zinc">{{ $question->type->label() }}</flux:badge>@if ($question->awarded_points !== null)<flux:badge :color="$question->awarded_points > 0 ? 'green' : 'red'">{{ $question->awarded_points }} / {{ $question->points }} puntos</flux:badge>@else<flux:badge color="amber">Requiere revisión</flux:badge>@endif</div>
                <p class="mt-4 font-semibold text-zinc-950 dark:text-white">{{ $question->prompt }}</p>
                <div class="mt-4 rounded-xl bg-zinc-50 p-4 text-sm dark:bg-zinc-800"><span class="block text-xs font-semibold uppercase tracking-wide text-zinc-500">Respuesta del colaborador</span><p class="mt-2 whitespace-pre-line">{{ $question->answer ?? 'Sin respuesta' }}</p></div>

                @if ($question->type !== ExamQuestionType::ShortAnswer)
                    <div class="mt-3 grid gap-2 text-sm sm:grid-cols-2"><p><span class="font-semibold">Respuesta correcta:</span> {{ $question->correct_answer }}</p><p><span class="font-semibold">Resultado:</span> {{ $question->is_correct ? 'Correcta' : 'Incorrecta' }}</p></div>
                @elseif ($attempt->status === ExamAttemptStatus::PendingReview && filled($question->answer))
                    <div class="mt-5 grid gap-4 border-t border-zinc-200 pt-5 dark:border-zinc-700 sm:grid-cols-[10rem_1fr_auto] sm:items-end">
                        <flux:input wire:model="awardedPoints.{{ $question->id }}" type="number" min="0" max="{{ $question->points }}" label="Puntos (0-{{ $question->points }})" required />
                        <flux:input wire:model="reviewComments.{{ $question->id }}" label="Comentario para el colaborador" placeholder="Opcional" />
                        <flux:button wire:click="saveReview({{ $question->id }})" variant="primary" icon="check">Guardar revisión</flux:button>
                    </div>
                @elseif ($question->review_comment)
                    <div class="mt-3 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-100"><span class="font-semibold">Comentario enviado:</span> {{ $question->review_comment }}</div>
                @endif
            </article>
        @endforeach
    </section>

    <section class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div><flux:heading size="lg">Registro de seguridad</flux:heading><flux:subheading class="mt-1">Estas señales ayudan a revisar el comportamiento; por sí solas no prueban una falta.</flux:subheading></div>
        @forelse ($this->events as $event)
            <div class="flex items-center justify-between gap-4 rounded-xl border border-red-100 bg-red-50 p-3 text-sm dark:border-red-900 dark:bg-red-950/20" wire:key="event-{{ $event->id }}"><span>{{ $event->type->label() }}</span><span class="text-xs text-zinc-500">{{ $event->occurred_at->format('H:i:s') }}</span></div>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-700"><flux:text>No se registraron eventos sospechosos.</flux:text></div>
        @endforelse
    </section>
</section>
