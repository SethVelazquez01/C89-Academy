<?php

use App\Actions\Courses\MarkLessonComplete;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonType;
use App\Enums\TeamRole;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public Course $courseModel;

    public CourseEnrollment $enrollment;

    public ?int $selectedLessonId = null;

    public function mount(string $course): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->currentTeam !== null, 403);
        abort_unless($user->teamRole($user->currentTeam) === TeamRole::Member, 403);

        $this->courseModel = $user->currentTeam
            ->courses()
            ->where('slug', $course)
            ->firstOrFail();

        Gate::authorize('view', $this->courseModel);

        $enrollment = $user->courseEnrollments()
            ->where('course_id', $this->courseModel->id)
            ->whereIn('status', [
                EnrollmentStatus::Active->value,
                EnrollmentStatus::Completed->value,
            ])
            ->first();

        abort_unless($enrollment instanceof CourseEnrollment, 403);

        $this->enrollment = $enrollment;

        $publishedLessons = $this->publishedLessons();
        $completedLessonIds = $this->enrollment
            ->lessonProgress()
            ->whereNotNull('completed_at')
            ->pluck('lesson_id');
        $firstPendingLesson = $publishedLessons
            ->first(fn (Lesson $lesson) => ! $completedLessonIds->contains($lesson->id));
        $firstLesson = $firstPendingLesson ?? $publishedLessons->first();

        if ($firstLesson instanceof Lesson) {
            $this->selectedLessonId = $firstLesson->id;
            $this->startLesson($firstLesson);
        }
    }

    public function selectLesson(int $lessonId): void
    {
        $lesson = $this->findPublishedLesson($lessonId);

        Gate::authorize('view', $lesson);

        $this->selectedLessonId = $lesson->id;
        $this->startLesson($lesson);
    }

    public function markSelectedLessonComplete(): void
    {
        abort_if($this->selectedLessonId === null, 422);

        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        $lesson = $this->findPublishedLesson($this->selectedLessonId);

        app(MarkLessonComplete::class)->handle(
            $user,
            $this->enrollment,
            $lesson,
        );

        $this->enrollment->refresh();

        $completedLessonIds = $this->enrollment
            ->lessonProgress()
            ->whereNotNull('completed_at')
            ->pluck('lesson_id');
        $nextLesson = $this->publishedLessons()
            ->first(fn (Lesson $candidate) => ! $completedLessonIds->contains($candidate->id));

        if ($nextLesson instanceof Lesson) {
            $this->selectedLessonId = $nextLesson->id;
            $this->startLesson($nextLesson);
            Flux::toast(variant: 'success', text: 'Lección completada. Continúa con la siguiente.');

            return;
        }

        Flux::toast(variant: 'success', text: '¡Completaste todas las lecciones del curso!');
    }

    private function startLesson(Lesson $lesson): void
    {
        Gate::authorize('create', [
            LessonProgress::class,
            $this->enrollment,
            $lesson,
        ]);

        $this->enrollment->lessonProgress()->firstOrCreate(
            ['lesson_id' => $lesson->id],
            ['started_at' => now(), 'completed_at' => null],
        );
    }

    private function findPublishedLesson(int $lessonId): Lesson
    {
        return Lesson::query()
            ->whereKey($lessonId)
            ->where('is_published', true)
            ->whereHas('courseModule', fn ($query) => $query
                ->where('course_id', $this->courseModel->id)
                ->where('is_published', true))
            ->firstOrFail();
    }

    /**
     * Get all visible lessons in course order.
     *
     * @return Collection<int, Lesson>
     */
    private function publishedLessons(): Collection
    {
        return $this->courseModel
            ->modules()
            ->where('is_published', true)
            ->with(['lessons' => fn ($query) => $query->where('is_published', true)])
            ->get()
            ->flatMap(fn ($courseModule) => $courseModule->lessons)
            ->values();
    }

    public function render()
    {
        $courseModules = $this->courseModel
            ->modules()
            ->where('is_published', true)
            ->with(['lessons' => fn ($query) => $query->where('is_published', true)])
            ->get();
        $publishedLessons = $courseModules
            ->flatMap(fn ($courseModule) => $courseModule->lessons)
            ->values();
        $progressByLesson = $this->enrollment
            ->lessonProgress()
            ->get()
            ->keyBy('lesson_id');
        $completedLessonsCount = $progressByLesson
            ->whereNotNull('completed_at')
            ->count();
        $totalLessonsCount = $publishedLessons->count();
        $progressPercentage = $totalLessonsCount > 0
            ? (int) round(($completedLessonsCount / $totalLessonsCount) * 100)
            : 0;
        $currentLesson = $publishedLessons
            ->firstWhere('id', $this->selectedLessonId);

        return $this->view([
            'courseModules' => $courseModules,
            'progressByLesson' => $progressByLesson,
            'completedLessonsCount' => $completedLessonsCount,
            'totalLessonsCount' => $totalLessonsCount,
            'progressPercentage' => $progressPercentage,
            'currentLesson' => $currentLesson,
        ])->title($this->courseModel->title);
    }
};
?>

<section class="mx-auto w-full max-w-7xl space-y-6">
    <header class="rounded-3xl border border-[#174667] bg-[#062b4f] p-6 text-white shadow-xl shadow-[#062b4f]/15 md:p-8">
        <flux:button :href="route('dashboard')" variant="ghost" icon="arrow-left" wire:navigate class="text-white">
            Volver a mi aprendizaje
        </flux:button>

        <div class="mt-5 grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-end">
            <div>
                <span class="text-sm font-semibold uppercase tracking-wide text-[#8bd15a]">Curso en progreso</span>
                <h1 class="mt-2 text-3xl font-bold tracking-tight text-white md:text-4xl">{{ $courseModel->title }}</h1>
                @if ($courseModel->summary)
                    <p class="mt-3 max-w-3xl leading-7 text-blue-100">{{ $courseModel->summary }}</p>
                @endif
            </div>

            <div>
                <div class="flex items-center justify-between text-sm">
                    <span>{{ $completedLessonsCount }} de {{ $totalLessonsCount }} lecciones</span>
                    <span class="font-bold">{{ $progressPercentage }}%</span>
                </div>
                <div class="mt-2 h-3 overflow-hidden rounded-full bg-white/15">
                    <div class="h-full rounded-full bg-[#63b32e] transition-all" style="width: {{ $progressPercentage }}%"></div>
                </div>
            </div>
        </div>
    </header>

    @if ($totalLessonsCount === 0)
        <div class="rounded-2xl border border-zinc-200 bg-white p-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <flux:icon name="book-open" class="mx-auto size-10 text-zinc-400" />
            <h2 class="mt-4 text-xl font-semibold text-zinc-950 dark:text-white">Contenido en preparación</h2>
            <p class="mt-2 text-zinc-600 dark:text-zinc-300">El administrador todavía no ha publicado lecciones para este curso.</p>
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-[21rem_minmax(0,1fr)] lg:items-start">
            <aside class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:sticky lg:top-6">
                <div class="border-b border-zinc-200 p-5 dark:border-zinc-700">
                    <h2 class="font-semibold text-zinc-950 dark:text-white">Contenido del curso</h2>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Selecciona una lección para continuar.</p>
                </div>

                <div class="max-h-[70vh] overflow-y-auto">
                    @foreach ($courseModules as $courseModule)
                        <section class="border-b border-zinc-200 last:border-b-0 dark:border-zinc-700">
                            <div class="bg-zinc-50 px-5 py-3 dark:bg-zinc-800/70">
                                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    Módulo {{ $courseModule->position }}
                                </p>
                                <h3 class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ $courseModule->title }}</h3>
                            </div>

                            <div class="p-2">
                                @foreach ($courseModule->lessons as $lesson)
                                    @php($lessonProgress = $progressByLesson->get($lesson->id))
                                    <button
                                        type="button"
                                        wire:click="selectLesson({{ $lesson->id }})"
                                        class="flex w-full items-start gap-3 rounded-xl px-3 py-3 text-left transition {{ $selectedLessonId === $lesson->id ? 'bg-[#63b32e]/15' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"
                                    >
                                        <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full {{ $lessonProgress?->completed_at ? 'bg-[#63b32e] text-white' : 'border border-zinc-300 text-zinc-500 dark:border-zinc-600' }}">
                                            @if ($lessonProgress?->completed_at)
                                                <flux:icon name="check" class="size-4" />
                                            @else
                                                <span class="text-xs">{{ $lesson->position }}</span>
                                            @endif
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ $lesson->title }}</span>
                                            <span class="mt-1 block text-xs text-zinc-500 dark:text-zinc-400">
                                                {{ $lesson->type->label() }}
                                                @if ($lesson->estimated_duration_minutes)
                                                    · {{ $lesson->estimated_duration_minutes }} min
                                                @endif
                                            </span>
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
            </aside>

            <main class="min-w-0 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 md:p-8">
                @if ($currentLesson instanceof Lesson)
                    @php($currentProgress = $progressByLesson->get($currentLesson->id))

                    <div class="flex flex-col gap-4 border-b border-zinc-200 pb-6 dark:border-zinc-700 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:badge color="blue">{{ $currentLesson->type->label() }}</flux:badge>
                                @if ($currentProgress?->completed_at)
                                    <flux:badge color="green">Completada</flux:badge>
                                @else
                                    <flux:badge color="zinc">En progreso</flux:badge>
                                @endif
                            </div>
                            <h2 class="mt-3 text-2xl font-bold text-zinc-950 dark:text-white">{{ $currentLesson->title }}</h2>
                        </div>

                        @if ($currentLesson->estimated_duration_minutes)
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $currentLesson->estimated_duration_minutes }} minutos</span>
                        @endif
                    </div>

                    <div class="py-8">
                        @if ($currentLesson->type === LessonType::Text)
                            <div class="whitespace-pre-line text-base leading-8 text-zinc-700 dark:text-zinc-200">{{ $currentLesson->content }}</div>
                        @elseif ($currentLesson->type === LessonType::Document)
                            <div class="rounded-2xl border border-zinc-200 p-6 text-center dark:border-zinc-700">
                                <flux:icon name="document-arrow-down" class="mx-auto size-12 text-[#3d8d25] dark:text-[#8bd15a]" />
                                <h3 class="mt-4 text-lg font-semibold text-zinc-950 dark:text-white">{{ $currentLesson->resource_name ?? 'Documento PDF' }}</h3>
                                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Este archivo está protegido y requiere tu sesión activa.</p>
                                <flux:button
                                    :href="route('lessons.resource', [
                                        'current_team' => $courseModel->team,
                                        'course' => $courseModel,
                                        'courseModule' => $currentLesson->courseModule,
                                        'lesson' => $currentLesson,
                                    ])"
                                    variant="primary"
                                    icon="arrow-down-tray"
                                    class="mt-5"
                                >
                                    Descargar PDF
                                </flux:button>
                            </div>
                        @else
                            <div class="rounded-2xl border border-zinc-200 p-6 text-center dark:border-zinc-700">
                                <flux:icon :name="$currentLesson->type === LessonType::Video ? 'play-circle' : 'arrow-top-right-on-square'" class="mx-auto size-12 text-[#3d8d25] dark:text-[#8bd15a]" />
                                <h3 class="mt-4 text-lg font-semibold text-zinc-950 dark:text-white">
                                    {{ $currentLesson->type === LessonType::Video ? 'Video de la lección' : 'Recurso externo' }}
                                </h3>
                                <flux:button
                                    :href="$currentLesson->external_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    variant="primary"
                                    icon="arrow-top-right-on-square"
                                    class="mt-5"
                                >
                                    {{ $currentLesson->type === LessonType::Video ? 'Abrir video' : 'Abrir enlace' }}
                                </flux:button>
                            </div>
                        @endif
                    </div>

                    <div class="flex justify-end border-t border-zinc-200 pt-6 dark:border-zinc-700">
                        @if ($currentProgress?->completed_at)
                            <span class="inline-flex items-center gap-2 rounded-xl bg-[#63b32e]/15 px-4 py-3 font-semibold text-[#3d8d25] dark:text-[#8bd15a]">
                                <flux:icon name="check-circle" class="size-5" />
                                Lección completada
                            </span>
                        @else
                            <flux:button
                                wire:click="markSelectedLessonComplete"
                                variant="primary"
                                icon="check-circle"
                                data-test="complete-lesson-button"
                            >
                                Marcar como completada
                            </flux:button>
                        @endif
                    </div>
                @endif
            </main>
        </div>
    @endif
</section>
