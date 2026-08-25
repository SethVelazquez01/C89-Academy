<?php

use App\Enums\ExamAttemptStatus;
use App\Models\Exam;
use App\Models\ExamAttempt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Exam $examModel;

    public string $statusFilter = 'all';

    public function mount(string $exam): void
    {
        $team = Auth::user()->currentTeam;
        abort_if($team === null, 403);

        $this->examModel = $team->exams()->where('slug', $exam)->firstOrFail();
        Gate::authorize('review', $this->examModel);
    }

    /** @return Collection<int, ExamAttempt> */
    #[Computed]
    public function attempts(): Collection
    {
        return $this->examModel->attempts()
            ->with('user')
            ->withCount([
                'questions as answered_questions_count' => fn ($query) => $query->whereNotNull('answered_at'),
                'events',
            ])
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest('started_at')
            ->get();
    }

    public function updatedStatusFilter(): void
    {
        unset($this->attempts);
    }

    public function render()
    {
        return $this->view()->title('Intentos del examen');
    }
};
?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <header>
        <flux:button :href="route('admin.exams.index')" variant="ghost" icon="arrow-left" wire:navigate>Volver a exámenes</flux:button>
        <div class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div><flux:heading size="xl">Intentos y resultados</flux:heading><flux:subheading class="mt-1">{{ $examModel->title }}</flux:subheading></div>
            <div class="w-full sm:w-56"><flux:select wire:model.live="statusFilter" label="Mostrar">
                <flux:select.option value="all">Todos los intentos</flux:select.option>
                @foreach (ExamAttemptStatus::cases() as $status)<flux:select.option :value="$status->value">{{ $status->label() }}</flux:select.option>@endforeach
            </flux:select></div>
        </div>
    </header>

    <div class="grid gap-4 sm:grid-cols-3">
        <article class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"><p class="text-sm text-zinc-500">Intentos</p><p class="mt-2 text-3xl font-bold">{{ $examModel->attempts()->count() }}</p></article>
        <article class="rounded-2xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950/30"><p class="text-sm text-amber-700 dark:text-amber-200">Pendientes de revisión</p><p class="mt-2 text-3xl font-bold text-amber-900 dark:text-amber-100">{{ $examModel->attempts()->where('status', ExamAttemptStatus::PendingReview)->count() }}</p></article>
        <article class="rounded-2xl border border-red-200 bg-red-50 p-5 dark:border-red-900 dark:bg-red-950/30"><p class="text-sm text-red-700 dark:text-red-200">Con alertas</p><p class="mt-2 text-3xl font-bold text-red-900 dark:text-red-100">{{ $examModel->attempts()->where('security_event_count', '>', 0)->count() }}</p></article>
    </div>

    @if ($this->attempts->isEmpty())
        <div class="rounded-2xl border border-dashed border-zinc-300 bg-white px-6 py-16 text-center dark:border-zinc-700 dark:bg-zinc-900"><flux:icon name="clipboard-document-list" class="mx-auto size-9 text-zinc-400" /><flux:heading size="lg" class="mt-4">No hay intentos para mostrar</flux:heading><flux:text class="mt-2">Los resultados aparecerán aquí cuando los colaboradores presenten el examen.</flux:text></div>
    @else
        <div class="space-y-3">
            @foreach ($this->attempts as $attempt)
                <article class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900 md:flex-row md:items-center" wire:key="admin-attempt-{{ $attempt->id }}">
                    <div class="min-w-0 flex-1"><p class="font-semibold text-zinc-950 dark:text-white">{{ $attempt->user->name }}</p><p class="mt-1 text-sm text-zinc-500">{{ $attempt->user->email }} · Intento {{ $attempt->attempt_number }}</p><p class="mt-2 text-xs text-zinc-500">Inicio: {{ $attempt->started_at->format('d/m/Y H:i') }} @if ($attempt->submitted_at) · Entrega: {{ $attempt->submitted_at->format('d/m/Y H:i') }} @endif</p></div>
                    <div class="flex flex-wrap items-center gap-2"><flux:badge :color="$attempt->status->color()">{{ $attempt->status->label() }}</flux:badge>@if ($attempt->percentage_score !== null)<flux:badge color="blue">{{ number_format($attempt->percentage_score, 0) }}%</flux:badge>@endif@if ($attempt->security_event_count > 0)<flux:badge color="red">{{ $attempt->security_event_count }} alertas</flux:badge>@endif</div>
                    <flux:button :href="route('admin.exams.review', ['exam' => $examModel, 'examAttempt' => $attempt])" variant="primary" size="sm" icon="eye" wire:navigate>{{ $attempt->status === ExamAttemptStatus::PendingReview ? 'Revisar ahora' : 'Ver detalle' }}</flux:button>
                </article>
            @endforeach
        </div>
    @endif
</section>
