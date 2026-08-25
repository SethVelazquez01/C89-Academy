<?php

use App\Enums\CourseAccessMode;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamAttemptStatus;
use App\Models\Roadmap;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public Roadmap $roadmapModel;

    public function mount(string $roadmap): void
    {
        $team = Auth::user()->currentTeam;
        abort_if($team === null, 403);

        $this->roadmapModel = $team->roadmaps()->where('slug', $roadmap)->firstOrFail();
        Gate::authorize('view', $this->roadmapModel);
    }

    public function render()
    {
        $user = Auth::user();
        $items = $this->roadmapModel->items()->with(['course', 'exam'])->get();
        $enrollments = $user->courseEnrollments()
            ->whereIn('course_id', $items->pluck('course_id')->filter())
            ->get()
            ->keyBy('course_id');
        $examAttempts = $user->examAttempts()
            ->whereIn('exam_id', $items->pluck('exam_id')->filter())
            ->latest('attempt_number')
            ->get()
            ->unique('exam_id')
            ->keyBy('exam_id');

        return $this->view([
            'items' => $items,
            'enrollments' => $enrollments,
            'examAttempts' => $examAttempts,
        ])->title($this->roadmapModel->title);
    }
};
?>

<section class="mx-auto w-full max-w-4xl space-y-6">
    <header>
        <flux:button :href="route('roadmaps.index')" variant="ghost" icon="arrow-left" wire:navigate>Volver a mis rutas</flux:button>
        <div class="mt-5 rounded-3xl bg-[#062b4f] p-6 text-white md:p-8">
            <span class="text-xs font-semibold uppercase tracking-wide text-[#8bd15a]">Roadmap asignado</span>
            <h1 class="mt-3 text-3xl font-bold">{{ $roadmapModel->title }}</h1>
            @if ($roadmapModel->summary)<p class="mt-3 max-w-2xl text-blue-100">{{ $roadmapModel->summary }}</p>@endif
        </div>
    </header>

    <section class="space-y-3">
        @foreach ($items as $item)
            @php
                $content = $item->isCourse() ? $item->course : $item->exam;
                $enrollment = $item->isCourse() ? $enrollments->get($item->course_id) : null;
                $examAttempt = ! $item->isCourse() ? $examAttempts->get($item->exam_id) : null;
                $isBlocked = $item->isCourse() && ($content === null || $content->access_mode === CourseAccessMode::Blocked);
            @endphp
            <article class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:flex-row sm:items-center" wire:key="learning-roadmap-item-{{ $item->id }}">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-[#63b32e] text-lg font-bold text-white">{{ $item->position }}</span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap gap-2">
                        <flux:badge color="blue">{{ $item->isCourse() ? 'Curso' : 'Examen' }}</flux:badge>
                        @if ($item->isCourse() && $enrollment)<flux:badge :color="$enrollment->status === EnrollmentStatus::Completed ? 'green' : 'amber'">{{ $enrollment->status->label() }}</flux:badge>@endif
                        @if (! $item->isCourse() && $examAttempt)<flux:badge :color="$examAttempt->status->color()">{{ $examAttempt->status->label() }}</flux:badge>@endif
                    </div>
                    <h2 class="mt-2 text-lg font-semibold text-zinc-950 dark:text-white">{{ $content?->title ?? 'Contenido no disponible' }}</h2>
                    @if ($content?->summary)<p class="mt-1 text-sm text-zinc-500">{{ $content->summary }}</p>@endif
                </div>
                @if ($item->isCourse() && ! $isBlocked)
                    <flux:button :href="route('courses.show', ['course' => $content])" variant="primary" size="sm" icon="book-open" wire:navigate>{{ $enrollment?->status === EnrollmentStatus::Completed ? 'Revisar' : 'Abrir curso' }}</flux:button>
                @elseif (! $item->isCourse() && $content)
                    @if ($examAttempt && $examAttempt->status !== ExamAttemptStatus::InProgress)
                        <flux:button :href="route('exams.result', ['examAttempt' => $examAttempt])" variant="primary" size="sm" icon="clipboard-document-check" wire:navigate>Ver resultado</flux:button>
                    @else
                        <flux:button :href="route('exams.show', ['exam' => $content])" variant="primary" size="sm" icon="clipboard-document-check" wire:navigate>{{ $examAttempt ? 'Continuar examen' : 'Abrir examen' }}</flux:button>
                    @endif
                @else
                    <flux:badge color="red">No disponible</flux:badge>
                @endif
            </article>
        @endforeach
    </section>
</section>
