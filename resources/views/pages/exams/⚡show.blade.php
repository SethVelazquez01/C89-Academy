<?php

use App\Actions\Exams\StartExamAttempt;
use App\Enums\ExamAttemptStatus;
use App\Models\Exam;
use App\Models\ExamAttempt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Exam $examModel;

    public bool $acknowledged = false;

    public function mount(string $exam): void
    {
        $team = Auth::user()->currentTeam;
        abort_if($team === null, 403);

        $this->examModel = $team->exams()->where('slug', $exam)->firstOrFail();
        Gate::authorize('take', $this->examModel);
    }

    /** @return Collection<int, ExamAttempt> */
    #[Computed]
    public function attempts(): Collection
    {
        return $this->examModel->attempts()
            ->where('user_id', Auth::id())
            ->latest('attempt_number')
            ->get();
    }

    #[Computed]
    public function activeAttempt(): ?ExamAttempt
    {
        return $this->attempts->first(fn (ExamAttempt $attempt) => $attempt->status === ExamAttemptStatus::InProgress);
    }

    #[Computed]
    public function remainingAttempts(): int
    {
        return max(0, $this->examModel->max_attempts - $this->attempts->count());
    }

    public function start(StartExamAttempt $startExamAttempt): void
    {
        $this->validate([
            'acknowledged' => ['accepted'],
        ], ['acknowledged.accepted' => 'Confirma que comprendiste las reglas antes de comenzar.']);

        try {
            $attempt = $startExamAttempt->handle(Auth::user(), $this->examModel);
        } catch (ValidationException $exception) {
            throw $exception;
        }

        $this->redirectRoute('exams.take', [
            'current_team' => $this->examModel->team,
            'exam' => $this->examModel,
            'examAttempt' => $attempt,
        ], navigate: true);
    }

    public function render()
    {
        return $this->view()->title($this->examModel->title);
    }
};
?>

<section class="mx-auto w-full max-w-4xl space-y-6">
    <header>
        <flux:button :href="route('exams.index')" variant="ghost" icon="arrow-left" wire:navigate>Volver a exámenes</flux:button>
        <div class="mt-5 rounded-3xl bg-[#062b4f] p-6 text-white md:p-8">
            <div class="flex flex-wrap gap-2"><flux:badge color="green">Disponible</flux:badge><flux:badge color="amber">{{ $examModel->security_level->label() }}</flux:badge></div>
            <h1 class="mt-4 text-3xl font-bold">{{ $examModel->title }}</h1>
            <p class="mt-2 max-w-2xl text-blue-100">{{ $examModel->summary ?? 'Evaluación de conocimientos.' }}</p>
            <div class="mt-6 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl bg-white/10 p-4"><span class="text-xs text-blue-200">Tiempo</span><strong class="mt-1 block">{{ $examModel->time_limit_minutes }} minutos</strong></div>
                <div class="rounded-xl bg-white/10 p-4"><span class="text-xs text-blue-200">Aprobación</span><strong class="mt-1 block">{{ $examModel->passing_score }}%</strong></div>
                <div class="rounded-xl bg-white/10 p-4"><span class="text-xs text-blue-200">Intentos restantes</span><strong class="mt-1 block">{{ $this->remainingAttempts }}</strong></div>
            </div>
        </div>
    </header>

    @if ($this->activeAttempt)
        <div class="flex flex-col gap-4 rounded-2xl border border-blue-200 bg-blue-50 p-5 dark:border-blue-900 dark:bg-blue-950/30 sm:flex-row sm:items-center sm:justify-between">
            <div><flux:heading>Tienes un intento en curso</flux:heading><flux:text class="mt-1">Continúa donde te quedaste. El temporizador sigue avanzando.</flux:text></div>
            <flux:button :href="route('exams.take', ['exam' => $examModel, 'examAttempt' => $this->activeAttempt])" variant="primary" icon="arrow-right" wire:navigate>Continuar examen</flux:button>
        </div>
    @endif

    <section class="space-y-5 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 md:p-8">
        <div><flux:heading size="lg">Antes de comenzar</flux:heading><flux:subheading class="mt-1">Lee estas reglas con calma. El examen iniciará al presionar el botón.</flux:subheading></div>
        <ul class="space-y-3 text-sm text-zinc-700 dark:text-zinc-200">
            <li class="flex gap-3"><flux:icon name="clock" class="size-5 shrink-0 text-[#3d8d25]" /><span>El temporizador no se detiene si cierras o actualizas la página.</span></li>
            <li class="flex gap-3"><flux:icon name="arrows-right-left" class="size-5 shrink-0 text-[#3d8d25]" /><span>{{ $examModel->allow_backtracking ? 'Puedes regresar a preguntas anteriores.' : 'No podrás regresar después de avanzar.' }}</span></li>
            <li class="flex gap-3"><flux:icon name="shield-check" class="size-5 shrink-0 text-[#3d8d25]" /><span>Se registrarán cambios de pestaña, salidas de pantalla completa e intentos de copiar.</span></li>
            <li class="flex gap-3"><flux:icon name="pencil-square" class="size-5 shrink-0 text-[#3d8d25]" /><span>Las respuestas abiertas serán revisadas por un administrador.</span></li>
        </ul>

        @if ($examModel->instructions)
            <div class="rounded-xl bg-zinc-50 p-4 text-sm leading-6 dark:bg-zinc-800">{{ $examModel->instructions }}</div>
        @endif

        @if (! $this->activeAttempt && $this->remainingAttempts > 0)
            <form wire:submit="start" class="space-y-4 border-t border-zinc-200 pt-5 dark:border-zinc-700">
                <flux:checkbox wire:model="acknowledged" label="Comprendo las reglas y estoy listo para comenzar." data-test="exam-acknowledgement" />
                <div class="flex justify-end">
                    <flux:button
                        type="submit"
                        variant="primary"
                        icon="play"
                        data-test="exam-start"
                        x-on:click="if ({{ $examModel->fullscreen_required ? 'true' : 'false' }}) document.documentElement.requestFullscreen?.().catch(() => {})"
                    >
                        Iniciar examen
                    </flux:button>
                </div>
            </form>
        @elseif (! $this->activeAttempt && $this->remainingAttempts === 0)
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">Ya utilizaste todos los intentos permitidos.</div>
        @endif
    </section>

    @if ($this->attempts->isNotEmpty())
        <section class="space-y-3 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">Tus intentos</flux:heading>
            @foreach ($this->attempts as $attempt)
                <article class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700 sm:flex-row sm:items-center sm:justify-between" wire:key="attempt-{{ $attempt->id }}">
                    <div><p class="font-semibold">Intento {{ $attempt->attempt_number }}</p><p class="mt-1 text-sm text-zinc-500">{{ $attempt->started_at->format('d/m/Y H:i') }}</p></div>
                    <div class="flex items-center gap-2"><flux:badge :color="$attempt->status->color()">{{ $attempt->status->label() }}</flux:badge>@if ($attempt->status !== ExamAttemptStatus::InProgress)<flux:button :href="route('exams.result', ['examAttempt' => $attempt])" variant="ghost" size="sm" wire:navigate>Ver resultado</flux:button>@endif</div>
                </article>
            @endforeach
        </section>
    @endif
</section>
