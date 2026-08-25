<?php

use App\Enums\CourseAccessMode;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamStatus;
use App\Enums\RoadmapAssignmentStatus;
use App\Enums\RoadmapStatus;
use App\Enums\TeamRole;
use App\Models\Exam;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public function mount(): void
    {
        Gate::authorize('viewAny', Exam::class);
    }

    /** @return Collection<int, Exam> */
    #[Computed]
    public function exams(): Collection
    {
        $team = Auth::user()->currentTeam;
        abort_if($team === null, 403);

        $query = $team->exams()->where('status', ExamStatus::Published);

        if (Auth::user()->teamRole($team) === TeamRole::Member) {
            $query->where(function ($accessQuery) {
                $accessQuery
                    ->whereHas('courses', fn ($courseQuery) => $courseQuery
                        ->where('access_mode', '!=', CourseAccessMode::Blocked)
                        ->whereHas('enrollments', fn ($enrollmentQuery) => $enrollmentQuery
                            ->where('user_id', Auth::id())
                            ->where('status', EnrollmentStatus::Completed)))
                    ->orWhereHas('roadmapItems.roadmap', fn ($roadmapQuery) => $roadmapQuery
                        ->where('status', RoadmapStatus::Published)
                        ->whereHas('assignments', fn ($assignmentQuery) => $assignmentQuery
                            ->where('user_id', Auth::id())
                            ->where('status', RoadmapAssignmentStatus::Active)));
            });
        }

        return $query
            ->with(['attempts' => fn ($attemptQuery) => $attemptQuery->where('user_id', Auth::id())->latest('attempt_number')])
            ->withCount('questions')
            ->latest('published_at')
            ->get();
    }
};
?>

<section class="mx-auto w-full max-w-7xl space-y-6" data-test="exam-catalog">
    <header class="relative overflow-hidden rounded-3xl border border-[#174667] bg-[#062b4f] p-6 text-white md:p-8">
        <div class="pointer-events-none absolute -right-16 -top-20 size-64 rounded-full bg-[#63b32e]/20 blur-3xl"></div>
        <div class="relative z-10 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between"><div><span class="rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-wide">Evaluaciones C89</span><h1 class="mt-4 text-3xl font-bold">Exámenes</h1><p class="mt-2 max-w-2xl text-blue-100">Aquí aparecen los exámenes habilitados por tus cursos y roadmaps.</p></div>@can('create', Exam::class)<flux:button :href="route('admin.exams.index')" variant="filled" icon="cog-6-tooth" wire:navigate>Administrar</flux:button>@endcan</div>
    </header>

    @if ($this->exams->isEmpty())
        <div class="rounded-2xl border border-dashed border-zinc-300 bg-white px-6 py-16 text-center dark:border-zinc-700 dark:bg-zinc-900"><div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-[#63b32e]/15 text-[#3d8d25]"><flux:icon name="clipboard-document-check" class="size-7" /></div><flux:heading size="lg" class="mt-5">No tienes exámenes disponibles</flux:heading><flux:text class="mx-auto mt-2 max-w-lg">Los exámenes aparecerán al completar un curso o cuando formen parte de un roadmap asignado.</flux:text></div>
    @else
        <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->exams as $exam)
                @php($latestAttempt = $exam->attempts->first())
                <article class="flex flex-col rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-start justify-between"><flux:badge color="green">Publicado</flux:badge><flux:badge :color="$exam->security_level === \App\Enums\ExamSecurityLevel::Controlled ? 'amber' : 'blue'">{{ $exam->security_level->label() }}</flux:badge></div>
                    <flux:heading size="lg" class="mt-5">{{ $exam->title }}</flux:heading>
                    <flux:text class="mt-2 flex-1">{{ $exam->summary ?? 'Evaluación de conocimientos y aplicación práctica.' }}</flux:text>
                    <div class="mt-5 grid grid-cols-3 gap-2 rounded-xl bg-zinc-50 p-3 text-center text-xs dark:bg-zinc-800"><div><strong class="block text-sm">{{ $exam->questions_count }}</strong>preguntas</div><div><strong class="block text-sm">{{ $exam->time_limit_minutes }}</strong>minutos</div><div><strong class="block text-sm">{{ $exam->passing_score }}%</strong>aprobación</div></div>
                    @if ($latestAttempt)<div class="mt-4 flex items-center justify-between rounded-xl border border-zinc-200 p-3 dark:border-zinc-700"><span class="text-sm">Intento {{ $latestAttempt->attempt_number }}</span><flux:badge :color="$latestAttempt->status->color()">{{ $latestAttempt->status->label() }}</flux:badge></div>@endif
                    @if (Auth::user()->teamRole(Auth::user()->currentTeam) === TeamRole::Member)
                        <flux:button :href="route('exams.show', ['exam' => $exam])" variant="primary" class="mt-4 w-full" icon="arrow-right" wire:navigate>{{ $latestAttempt?->status === \App\Enums\ExamAttemptStatus::InProgress ? 'Continuar examen' : 'Ver examen' }}</flux:button>
                    @else
                        <flux:button :href="route('admin.exams.attempts', ['exam' => $exam])" variant="primary" class="mt-4 w-full" icon="users" wire:navigate>Ver intentos</flux:button>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</section>
