<?php

use App\Actions\Exams\SubmitExamAttempt;
use App\Enums\ExamAttemptEventType;
use App\Enums\ExamAttemptStatus;
use App\Enums\ExamQuestionType;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptQuestion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    public Exam $examModel;

    public ExamAttempt $attempt;

    #[Locked]
    public int $currentPosition = 1;

    public string $answer = '';

    public function mount(string $exam, string $examAttempt): void
    {
        $team = Auth::user()->currentTeam;
        abort_if($team === null, 403);

        $this->examModel = $team->exams()->where('slug', $exam)->firstOrFail();
        $this->attempt = $this->examModel->attempts()->where('uuid', $examAttempt)->firstOrFail();
        Gate::authorize('view', $this->attempt);

        if ($this->attempt->status !== ExamAttemptStatus::InProgress) {
            $this->redirectRoute('exams.result', ['examAttempt' => $this->attempt], navigate: true);

            return;
        }

        if ($this->attempt->expires_at->isPast()) {
            app(SubmitExamAttempt::class)->handle(Auth::user(), $this->attempt, timedOut: true);
            $this->redirectRoute('exams.result', ['examAttempt' => $this->attempt], navigate: true);

            return;
        }

        $firstUnanswered = $this->attempt->questions()->whereNull('answered_at')->value('position');
        $this->currentPosition = (int) ($firstUnanswered ?? $this->attempt->questions()->max('position'));
        $this->loadAnswer();
    }

    #[Computed]
    public function currentQuestion(): ExamAttemptQuestion
    {
        return $this->attempt->questions()->where('position', $this->currentPosition)->firstOrFail();
    }

    #[Computed]
    public function totalQuestions(): int
    {
        return $this->attempt->questions()->count();
    }

    #[Computed]
    public function answeredQuestions(): int
    {
        return $this->attempt->questions()->whereNotNull('answered_at')->count();
    }

    public function updatedAnswer(): void
    {
        $this->saveAnswer();
    }

    public function saveAndNext(): void
    {
        if (! $this->saveAnswer()) {
            return;
        }

        if ($this->currentPosition < $this->totalQuestions) {
            $this->currentPosition++;
            $this->loadAnswer();
        }
    }

    public function previous(): void
    {
        abort_unless($this->examModel->allow_backtracking, 403);

        $this->saveAnswer();

        if ($this->currentPosition > 1) {
            $this->currentPosition--;
            $this->loadAnswer();
        }
    }

    public function goTo(int $position): void
    {
        abort_unless($this->examModel->allow_backtracking, 403);
        abort_unless($position >= 1 && $position <= $this->totalQuestions, 422);

        $this->saveAnswer();
        $this->currentPosition = $position;
        $this->loadAnswer();
    }

    public function submit(SubmitExamAttempt $submitExamAttempt): void
    {
        if (! $this->saveAnswer()) {
            return;
        }

        $submitExamAttempt->handle(Auth::user(), $this->attempt);
        $this->redirectRoute('exams.result', ['examAttempt' => $this->attempt], navigate: true);
    }

    public function checkTime(SubmitExamAttempt $submitExamAttempt): void
    {
        $this->attempt->refresh();

        if ($this->attempt->status === ExamAttemptStatus::InProgress && $this->attempt->expires_at->isPast()) {
            $submitExamAttempt->handle(Auth::user(), $this->attempt, timedOut: true);
            $this->redirectRoute('exams.result', ['examAttempt' => $this->attempt], navigate: true);
        }
    }

    public function recordSecurityEvent(string $type): void
    {
        $eventType = ExamAttemptEventType::tryFrom($type);

        if ($eventType === null) {
            return;
        }

        $this->attempt->refresh();

        if ($this->attempt->status !== ExamAttemptStatus::InProgress) {
            return;
        }

        $wasRecentlyRecorded = $this->attempt->events()
            ->where('type', $eventType)
            ->where('occurred_at', '>=', now()->subSeconds(2))
            ->exists();

        if ($wasRecentlyRecorded) {
            return;
        }

        $this->attempt->events()->create(['type' => $eventType, 'occurred_at' => now()]);
        $this->attempt->increment('security_event_count');
    }

    private function saveAnswer(): bool
    {
        $this->attempt->refresh();

        if ($this->attempt->status !== ExamAttemptStatus::InProgress) {
            return false;
        }

        if ($this->attempt->expires_at->isPast()) {
            app(SubmitExamAttempt::class)->handle(Auth::user(), $this->attempt, timedOut: true);
            $this->redirectRoute('exams.result', ['examAttempt' => $this->attempt], navigate: true);

            return false;
        }

        Gate::authorize('update', $this->attempt);
        $question = $this->currentQuestion;
        $rules = $question->type === ExamQuestionType::ShortAnswer
            ? ['answer' => ['nullable', 'string', 'max:10000']]
            : ['answer' => ['nullable', 'string', Rule::in($question->options ?? [])]];
        $validated = $this->validate($rules);

        $question->update([
            'answer' => filled($validated['answer']) ? $validated['answer'] : null,
            'answered_at' => filled($validated['answer']) ? now() : null,
        ]);
        $this->attempt->update(['last_activity_at' => now()]);
        unset($this->answeredQuestions);

        return true;
    }

    private function loadAnswer(): void
    {
        unset($this->currentQuestion);
        $this->answer = $this->currentQuestion->answer ?? '';
        $this->resetValidation('answer');
    }

    public function render()
    {
        return $this->view()->title('Examen en curso');
    }
};
?>

<section
    class="mx-auto w-full max-w-5xl select-none space-y-5"
    wire:poll.15s="checkTime"
    x-data="{ remaining: {{ $attempt->secondsRemaining() }}, timer: null, sent: false }"
    x-init="timer = setInterval(() => { if (remaining > 0) remaining--; if (remaining === 0 && !sent) { sent = true; $wire.checkTime(); } }, 1000)"
    x-on:visibilitychange.document="if (document.hidden) $wire.recordSecurityEvent('tab_hidden')"
    x-on:blur.window="$wire.recordSecurityEvent('window_blurred')"
    x-on:fullscreenchange.document="if (!document.fullscreenElement) $wire.recordSecurityEvent('fullscreen_exited')"
    x-on:copy.prevent="$wire.recordSecurityEvent('copy_attempted')"
    x-on:contextmenu.prevent="$wire.recordSecurityEvent('context_menu_attempted')"
>
    <header class="sticky top-0 z-20 rounded-2xl border border-zinc-200 bg-white/95 p-4 shadow-sm backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div><p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $examModel->title }}</p><p class="mt-1 font-semibold">Pregunta {{ $currentPosition }} de {{ $this->totalQuestions }}</p></div>
            <div class="flex items-center gap-3">
                <flux:badge color="blue">Guardado automático</flux:badge>
                <div class="rounded-xl bg-[#062b4f] px-4 py-2 text-center text-white"><span class="block text-xs text-blue-200">Tiempo restante</span><strong class="font-mono text-lg" x-text="`${String(Math.floor(remaining / 60)).padStart(2, '0')}:${String(remaining % 60).padStart(2, '0')}`"></strong></div>
            </div>
        </div>
        <div class="mt-3 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"><div class="h-full rounded-full bg-[#63b32e]" style="width: {{ ($currentPosition / $this->totalQuestions) * 100 }}%"></div></div>
    </header>

    <div class="grid gap-5 lg:grid-cols-[1fr_14rem]">
        <main class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 md:p-8">
            <div class="flex flex-wrap items-center gap-2"><flux:badge color="zinc">{{ $this->currentQuestion->type->label() }}</flux:badge><span class="text-sm text-zinc-500">{{ $this->currentQuestion->points }} {{ $this->currentQuestion->points === 1 ? 'punto' : 'puntos' }}</span></div>
            <h1 class="mt-5 text-xl font-semibold leading-8 text-zinc-950 dark:text-white">{{ $this->currentQuestion->prompt }}</h1>

            @if ($this->currentQuestion->type === ExamQuestionType::ShortAnswer)
                <div class="mt-6"><flux:textarea wire:model.live.debounce.800ms="answer" label="Tu respuesta" rows="8" placeholder="Escribe una respuesta clara y completa..." data-test="exam-answer" /></div>
            @else
                <fieldset class="mt-6 space-y-3">
                    <legend class="sr-only">Selecciona una respuesta</legend>
                    @foreach ($this->currentQuestion->options ?? [] as $option)
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 p-4 transition hover:border-[#63b32e] has-[:checked]:border-[#63b32e] has-[:checked]:bg-[#63b32e]/10 dark:border-zinc-700">
                            <input type="radio" wire:model.live="answer" value="{{ $option }}" class="mt-1 size-4 accent-[#63b32e]" />
                            <span>{{ $option }}</span>
                        </label>
                    @endforeach
                </fieldset>
            @endif

            <div class="mt-8 flex flex-col-reverse gap-3 border-t border-zinc-200 pt-5 dark:border-zinc-700 sm:flex-row sm:justify-between">
                @if ($examModel->allow_backtracking && $currentPosition > 1)
                    <flux:button wire:click="previous" variant="ghost" icon="arrow-left">Anterior</flux:button>
                @else
                    <span></span>
                @endif
                @if ($currentPosition < $this->totalQuestions)
                    <flux:button wire:click="saveAndNext" variant="primary" icon-trailing="arrow-right" data-test="exam-next">Guardar y continuar</flux:button>
                @else
                    <flux:button wire:click="submit" wire:confirm="¿Entregar el examen? Después no podrás cambiar tus respuestas." variant="primary" icon="paper-airplane" data-test="exam-submit">Entregar examen</flux:button>
                @endif
            </div>
        </main>

        <aside class="h-fit rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm font-semibold">Progreso</p>
            <p class="mt-1 text-xs text-zinc-500">{{ $this->answeredQuestions }} de {{ $this->totalQuestions }} respondidas</p>
            <div class="mt-4 grid grid-cols-5 gap-2 lg:grid-cols-4">
                @foreach ($attempt->questions()->get() as $question)
                    <button
                        type="button"
                        @if ($examModel->allow_backtracking) wire:click="goTo({{ $question->position }})" @else disabled @endif
                        @class([
                            'flex size-9 items-center justify-center rounded-lg text-sm font-semibold',
                            'bg-[#63b32e] text-white' => $question->position === $currentPosition,
                            'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200' => $question->position !== $currentPosition && $question->answered_at,
                            'bg-zinc-100 text-zinc-500 dark:bg-zinc-800' => $question->position !== $currentPosition && ! $question->answered_at,
                            'cursor-pointer' => $examModel->allow_backtracking,
                        ])
                    >{{ $question->position }}</button>
                @endforeach
            </div>
            <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">Los cambios de pestaña y salidas de pantalla completa quedan registrados.</div>
        </aside>
    </div>
</section>
