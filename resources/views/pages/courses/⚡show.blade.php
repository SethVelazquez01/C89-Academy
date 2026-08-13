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
use Illuminate\Validation\ValidationException;
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

        if (! $this->canAccessLesson($lesson)) {
            throw ValidationException::withMessages([
                'lesson' => ['Completa las lecciones anteriores para continuar.'],
            ]);
        }

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

    private function canAccessLesson(Lesson $lesson): bool
    {
        $publishedLessons = $this->publishedLessons();
        $lessonIndex = $publishedLessons->search(
            fn (Lesson $candidate): bool => $candidate->id === $lesson->id,
        );

        if (! is_int($lessonIndex)) {
            return false;
        }

        $completedLessonIds = $this->enrollment
            ->lessonProgress()
            ->whereNotNull('completed_at')
            ->pluck('lesson_id');

        return $publishedLessons
            ->take($lessonIndex)
            ->every(fn (Lesson $previousLesson): bool => $completedLessonIds->contains($previousLesson->id));
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
        $completedLessonIds = $progressByLesson
            ->whereNotNull('completed_at')
            ->keys();
        $firstPendingLesson = $publishedLessons
            ->first(fn (Lesson $lesson) => ! $completedLessonIds->contains($lesson->id));
        $accessibleLessonIds = $completedLessonIds->values();

        if ($firstPendingLesson instanceof Lesson) {
            $accessibleLessonIds->push($firstPendingLesson->id);
        }
        $completedLessonsCount = $progressByLesson
            ->whereNotNull('completed_at')
            ->count();
        $totalLessonsCount = $publishedLessons->count();
        $progressPercentage = $totalLessonsCount > 0
            ? (int) round(($completedLessonsCount / $totalLessonsCount) * 100)
            : 0;
        $currentLesson = $publishedLessons
            ->firstWhere('id', $this->selectedLessonId);
        $currentProgress = $currentLesson instanceof Lesson
            ? $progressByLesson->get($currentLesson->id)
            : null;
        $currentResourceUrl = $currentLesson instanceof Lesson
            && in_array($currentLesson->type, [
                LessonType::Document,
                LessonType::Presentation,
                LessonType::Image,
            ], true)
                ? route('lessons.resource', [
                    'current_team' => $this->courseModel->team,
                    'course' => $this->courseModel,
                    'courseModule' => $currentLesson->courseModule,
                    'lesson' => $currentLesson,
                ])
                : null;

        return $this->view([
            'courseModules' => $courseModules,
            'progressByLesson' => $progressByLesson,
            'completedLessonsCount' => $completedLessonsCount,
            'totalLessonsCount' => $totalLessonsCount,
            'progressPercentage' => $progressPercentage,
            'currentLesson' => $currentLesson,
            'currentVideoEmbed' => $currentLesson?->videoEmbed(),
            'currentResourceUrl' => $currentResourceUrl,
            'accessibleLessonIds' => $accessibleLessonIds,
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
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Avanza en orden. Cada lección se desbloquea al completar la anterior.</p>
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
                                    @php($lessonIsAccessible = $accessibleLessonIds->contains($lesson->id))
                                    <button
                                        type="button"
                                        wire:click="selectLesson({{ $lesson->id }})"
                                        @disabled(! $lessonIsAccessible)
                                        class="flex w-full items-start gap-3 rounded-xl px-3 py-3 text-left transition {{ $selectedLessonId === $lesson->id ? 'bg-[#63b32e]/15' : ($lessonIsAccessible ? 'hover:bg-zinc-100 dark:hover:bg-zinc-800' : 'cursor-not-allowed opacity-50') }}"
                                    >
                                        <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full {{ $lessonProgress?->completed_at ? 'bg-[#63b32e] text-white' : 'border border-zinc-300 text-zinc-500 dark:border-zinc-600' }}">
                                            @if ($lessonProgress?->completed_at)
                                                <flux:icon name="check" class="size-4" />
                                            @elseif (! $lessonIsAccessible)
                                                <flux:icon name="lock-closed" class="size-3.5" />
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

            <main
                wire:key="lesson-content-{{ $currentLesson?->id ?? 'none' }}"
                x-data="presentationLesson(@js($currentLesson?->type->value), @js($currentResourceUrl))"
                class="min-w-0 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 md:p-8"
            >
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
                            <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-700">
                                <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                                    <p class="font-medium text-zinc-900 dark:text-white">{{ $currentLesson->resource_name ?? 'Documento PDF' }}</p>
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Documento privado visible únicamente durante tu sesión.</p>
                                </div>
                                <iframe
                                    src="{{ route('lessons.resource', [
                                        'current_team' => $courseModel->team,
                                        'course' => $courseModel,
                                        'courseModule' => $currentLesson->courseModule,
                                        'lesson' => $currentLesson,
                                    ]) }}#toolbar=0&navpanes=0"
                                    title="{{ $currentLesson->resource_name ?? 'Documento de la lección' }}"
                                    class="h-[70vh] min-h-[32rem] w-full bg-zinc-100 dark:bg-zinc-950"
                                    loading="lazy"
                                ></iframe>
                            </div>
                        @elseif ($currentLesson->type === LessonType::Presentation)
                            <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-700">
                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                                    <div>
                                        <p class="font-medium text-zinc-900 dark:text-white">{{ $currentLesson->resource_name ?? 'Presentación PowerPoint' }}</p>
                                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Presentación privada procesada dentro de la plataforma.</p>
                                    </div>
                                    <span x-show="slideCount > 0" x-text="`Diapositiva ${currentSlide + 1} de ${slideCount}`" class="text-xs font-medium text-zinc-500 dark:text-zinc-400"></span>
                                </div>

                                <div class="relative min-h-[32rem] bg-zinc-100 dark:bg-zinc-950">
                                    <div
                                        x-show="presentationLoading"
                                        class="absolute inset-0 z-10 flex items-center justify-center bg-zinc-100/90 text-sm text-zinc-600 dark:bg-zinc-950/90 dark:text-zinc-300"
                                    >
                                        Preparando la presentación…
                                    </div>
                                    <div
                                        x-show="presentationError"
                                        x-cloak
                                        class="absolute inset-0 z-20 flex items-center justify-center p-8 text-center text-sm text-red-700 dark:text-red-300"
                                    >
                                        <span x-text="presentationError"></span>
                                    </div>
                                    <div
                                        x-ref="presentationContainer"
                                        wire:ignore
                                        class="h-[70vh] min-h-[32rem] overflow-y-auto p-3 md:p-5"
                                    ></div>
                                </div>
                            </div>
                        @elseif ($currentLesson->type === LessonType::Image)
                            <figure class="overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-950">
                                <img
                                    src="{{ $currentResourceUrl }}"
                                    alt="{{ $currentLesson->title }}"
                                    class="mx-auto max-h-[75vh] w-full object-contain"
                                    loading="lazy"
                                    decoding="async"
                                >
                                @if (filled($currentLesson->content))
                                    <figcaption class="border-t border-zinc-200 bg-white px-5 py-4 text-center text-sm leading-6 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                                        {{ $currentLesson->content }}
                                    </figcaption>
                                @endif
                            </figure>
                        @elseif ($currentLesson->type === LessonType::Video)
                            @if ($currentVideoEmbed)
                                <div class="aspect-video overflow-hidden rounded-2xl bg-black shadow-sm" wire:key="video-{{ $currentLesson->id }}">
                                    <iframe
                                        src="{{ $currentVideoEmbed->embedUrl() }}"
                                        title="Video: {{ $currentLesson->title }}"
                                        class="size-full"
                                        loading="lazy"
                                        referrerpolicy="strict-origin-when-cross-origin"
                                        sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"
                                        allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; fullscreen"
                                        allowfullscreen
                                    ></iframe>
                                </div>
                            @else
                                <div class="rounded-2xl border border-red-200 bg-red-50 p-6 text-center text-red-800 dark:border-red-900/50 dark:bg-red-950/20 dark:text-red-200">
                                    El enlace del video no es compatible. Solicita al administrador un enlace de YouTube o Vimeo.
                                </div>
                            @endif
                        @else
                            <div class="rounded-2xl border border-zinc-200 p-6 text-center dark:border-zinc-700">
                                <flux:icon name="arrow-top-right-on-square" class="mx-auto size-12 text-[#3d8d25] dark:text-[#8bd15a]" />
                                <h3 class="mt-4 text-lg font-semibold text-zinc-950 dark:text-white">Recurso externo</h3>
                                <flux:button
                                    :href="$currentLesson->external_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    variant="primary"
                                    icon="arrow-top-right-on-square"
                                    class="mt-5"
                                >
                                    Abrir enlace
                                </flux:button>
                            </div>
                        @endif
                    </div>

                    <div class="flex justify-end border-t border-zinc-200 pt-6 dark:border-zinc-700">
                        @error('lesson')
                            <p class="mr-auto text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror

                        @if ($currentProgress?->completed_at)
                            <span class="inline-flex items-center gap-2 rounded-xl bg-[#63b32e]/15 px-4 py-3 font-semibold text-[#3d8d25] dark:text-[#8bd15a]">
                                <flux:icon name="check-circle" class="size-5" />
                                Lección completada
                            </span>
                        @else
                            <div class="flex w-full justify-end">
                                <flux:button
                                    wire:click="markSelectedLessonComplete"
                                    variant="primary"
                                    icon="check-circle"
                                    data-test="complete-lesson-button"
                                >
                                    Marcar como completada
                                </flux:button>
                            </div>
                        @endif
                    </div>
                @endif
            </main>
        </div>
    @endif
</section>

@script
<script>
    window.presentationLesson = (lessonType, resourceUrl) => ({
        lessonType,
        resourceUrl,
        presentationAbortController: null,
        presentationViewer: null,
        presentationLoading: lessonType === 'presentation',
        presentationError: '',
        currentSlide: 0,
        slideCount: 0,

        init() {
            if (this.lessonType === 'presentation') {
                this.$nextTick(() => this.loadPresentation());
            }
        },

        async loadPresentation() {
            if (! this.resourceUrl || ! this.$refs.presentationContainer) {
                this.presentationLoading = false;
                this.presentationError = 'La presentación no está disponible.';

                return;
            }

            this.presentationAbortController = new AbortController();

            try {
                this.presentationViewer = await window.openPrivatePresentation(
                    this.$refs.presentationContainer,
                    this.resourceUrl,
                    this.presentationAbortController.signal,
                    (index) => { this.currentSlide = index },
                );
                this.slideCount = this.presentationViewer.slideCount;
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    this.presentationError = error?.message ?? 'No fue posible mostrar la presentación.';
                }
            } finally {
                this.presentationLoading = false;
            }
        },

        destroy() {
            this.presentationAbortController?.abort();
            this.presentationViewer?.destroy();
        },
    });
</script>
@endscript
