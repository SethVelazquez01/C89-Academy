<?php

use App\Enums\ExamAttemptStatus;
use App\Enums\ExamQuestionType;
use App\Models\ExamAttempt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ExamAttempt $attempt;

    public function mount(string $examAttempt): void
    {
        $team = Auth::user()->currentTeam;
        abort_if($team === null, 403);

        $this->attempt = ExamAttempt::query()
            ->where('uuid', $examAttempt)
            ->whereHas('exam', fn ($query) => $query->where('team_id', $team->id))
            ->with(['exam', 'user'])
            ->firstOrFail();
        Gate::authorize('view', $this->attempt);
    }

    /** @return Collection<int, \App\Models\ExamAttemptQuestion> */
    #[Computed]
    public function questions(): Collection
    {
        return $this->attempt->questions()->with('reviewer')->get();
    }

    public function render()
    {
        return $this->view()->title('Resultado del examen');
    }
};
?>

<section class="mx-auto w-full max-w-4xl space-y-6">
    <header>
        <flux:button :href="route('exams.index')" variant="ghost" icon="arrow-left" wire:navigate>Volver a exámenes</flux:button>
        <div class="mt-5 rounded-3xl bg-[#062b4f] p-6 text-white md:p-8">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div><span class="text-xs font-semibold uppercase tracking-wide text-blue-200">Intento {{ $attempt->attempt_number }}</span><h1 class="mt-2 text-3xl font-bold">{{ $attempt->exam->title }}</h1><p class="mt-2 text-blue-100">Entregado {{ $attempt->submitted_at?->format('d/m/Y H:i') ?? 'en proceso' }}</p></div>
                <flux:badge :color="$attempt->status->color()" size="lg">{{ $attempt->status->label() }}</flux:badge>
            </div>
        </div>
    </header>

    @if ($attempt->status === ExamAttemptStatus::InProgress)
        <div class="flex flex-col gap-4 rounded-2xl border border-blue-200 bg-blue-50 p-5 dark:border-blue-900 dark:bg-blue-950/30 sm:flex-row sm:items-center sm:justify-between"><div><flux:heading>Este examen sigue en curso</flux:heading><flux:text class="mt-1">Continúa antes de que termine el tiempo.</flux:text></div><flux:button :href="route('exams.take', ['exam' => $attempt->exam, 'examAttempt' => $attempt])" variant="primary" wire:navigate>Continuar</flux:button></div>
    @elseif ($attempt->status === ExamAttemptStatus::PendingReview)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"><flux:heading size="lg">Tu examen está en revisión</flux:heading><p class="mt-2 text-sm leading-6">Las preguntas automáticas ya fueron calificadas. Un administrador revisará las respuestas abiertas antes de mostrar tu resultado final.</p></div>
    @else
        <section class="grid gap-4 sm:grid-cols-3">
            <article class="rounded-2xl border border-zinc-200 bg-white p-5 text-center dark:border-zinc-700 dark:bg-zinc-900"><p class="text-sm text-zinc-500">Resultado</p><p class="mt-2 text-4xl font-bold {{ $attempt->status === ExamAttemptStatus::Passed ? 'text-[#3d8d25]' : 'text-red-600' }}">{{ number_format((float) $attempt->percentage_score, 0) }}%</p></article>
            <article class="rounded-2xl border border-zinc-200 bg-white p-5 text-center dark:border-zinc-700 dark:bg-zinc-900"><p class="text-sm text-zinc-500">Puntos</p><p class="mt-2 text-3xl font-bold">{{ $attempt->earned_points }} / {{ $attempt->max_points }}</p></article>
            <article class="rounded-2xl border border-zinc-200 bg-white p-5 text-center dark:border-zinc-700 dark:bg-zinc-900"><p class="text-sm text-zinc-500">Para aprobar</p><p class="mt-2 text-3xl font-bold">{{ $attempt->exam->passing_score }}%</p></article>
        </section>
    @endif

    @if ($attempt->timed_out)
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-100">El intento fue entregado automáticamente al terminar el tiempo.</div>
    @endif

    @if ($attempt->status !== ExamAttemptStatus::InProgress)
        <section class="space-y-4">
            <div><flux:heading size="lg">Revisión de respuestas</flux:heading><flux:subheading class="mt-1">Consulta lo que respondiste y los puntos obtenidos.</flux:subheading></div>
            @foreach ($this->questions as $question)
                <article class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" wire:key="result-question-{{ $question->id }}">
                    <div class="flex flex-wrap items-center gap-2"><span class="flex size-7 items-center justify-center rounded-full bg-zinc-100 text-xs font-bold dark:bg-zinc-800">{{ $question->position }}</span><flux:badge color="zinc">{{ $question->type->label() }}</flux:badge>@if ($question->awarded_points !== null)<flux:badge :color="$question->awarded_points > 0 ? 'green' : 'red'">{{ $question->awarded_points }} / {{ $question->points }} puntos</flux:badge>@else<flux:badge color="amber">Pendiente</flux:badge>@endif</div>
                    <p class="mt-4 font-semibold text-zinc-950 dark:text-white">{{ $question->prompt }}</p>
                    <div class="mt-4 rounded-xl bg-zinc-50 p-4 text-sm dark:bg-zinc-800"><span class="block text-xs font-semibold uppercase tracking-wide text-zinc-500">Tu respuesta</span><p class="mt-2 whitespace-pre-line">{{ $question->answer ?? 'Sin respuesta' }}</p></div>
                    @if ($question->type !== ExamQuestionType::ShortAnswer && $attempt->status->isFinal())
                        <p class="mt-3 text-sm"><span class="font-semibold">Respuesta correcta:</span> {{ $question->correct_answer }}</p>
                    @endif
                    @if ($question->review_comment)<div class="mt-3 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-100"><span class="font-semibold">Comentario del revisor:</span> {{ $question->review_comment }}</div>@endif
                </article>
            @endforeach
        </section>
    @endif
</section>
