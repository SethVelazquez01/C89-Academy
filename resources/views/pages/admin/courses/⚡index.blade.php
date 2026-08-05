<?php

use App\Enums\CourseStatus;
use App\Models\Course;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $showTrash = false;

    public function mount(): void
    {
        Gate::authorize('create', Course::class);
    }

    /**
     * Get the courses for the currently selected organization.
     *
     * @return Collection<int, Course>
     */
    #[Computed]
    public function courses(): Collection
    {
        $team = Auth::user()->currentTeam;

        abort_if($team === null, 403);

        $courses = $team->courses();

        if ($this->showTrash) {
            $courses->onlyTrashed();
        }

        return $courses->latest()->get();
    }

    public function showActiveCourses(): void
    {
        $this->showTrash = false;
        unset($this->courses);
    }

    public function showTrashedCourses(): void
    {
        $this->showTrash = true;
        unset($this->courses);
    }

    public function restore(int $courseId): void
    {
        $team = Auth::user()->currentTeam;

        abort_if($team === null, 403);

        $course = $team->courses()
            ->onlyTrashed()
            ->whereKey($courseId)
            ->firstOrFail();

        Gate::authorize('restore', $course);

        $course->restore();
        $course->update([
            'status' => CourseStatus::Draft,
            'published_at' => null,
        ]);

        unset($this->courses);

        Flux::toast(variant: 'success', text: 'Curso restaurado como borrador.');
    }

    public function render()
    {
        return $this->view()->title('Administrar cursos');
    }
};
?>

<section class="mx-auto w-full max-w-7xl space-y-6">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">Cursos</flux:heading>
            <flux:subheading class="mt-1">Crea y administra el catálogo de {{ auth()->user()->currentTeam?->name }}.</flux:subheading>
        </div>

        <flux:button :href="route('admin.courses.create')" variant="primary" icon="plus" wire:navigate>
            Crear curso
        </flux:button>
    </header>

    <div class="flex gap-2 border-b border-zinc-200 pb-3 dark:border-zinc-700">
        <flux:button
            wire:click="showActiveCourses"
            :variant="$showTrash ? 'ghost' : 'primary'"
            size="sm"
            data-test="active-courses-tab"
        >
            Activos
        </flux:button>
        <flux:button
            wire:click="showTrashedCourses"
            :variant="$showTrash ? 'primary' : 'ghost'"
            size="sm"
            data-test="trashed-courses-tab"
        >
            Papelera
        </flux:button>
    </div>

    @if ($this->courses->isEmpty())
        <div class="rounded-2xl border border-dashed border-zinc-300 bg-white px-6 py-16 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-[#63b32e]/15 text-[#3d8d25] dark:text-[#8bd15a]">
                <flux:icon name="book-open" class="size-7" />
            </div>
            <flux:heading size="lg" class="mt-5">
                {{ $showTrash ? 'La papelera está vacía' : 'Todavía no hay cursos' }}
            </flux:heading>
            <flux:text class="mx-auto mt-2 max-w-lg">
                {{ $showTrash ? 'Los cursos eliminados aparecerán aquí y podrán restaurarse.' : 'Crea el primer curso de la organización. Se guardará como borrador hasta que decidas publicarlo.' }}
            </flux:text>
            @if (! $showTrash)
                <flux:button :href="route('admin.courses.create')" variant="primary" class="mt-6" wire:navigate>
                    Crear el primer curso
                </flux:button>
            @endif
        </div>
    @else
        <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->courses as $course)
                <article class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" wire:key="course-{{ $course->id }}">
                    <div class="flex items-start justify-between gap-4">
                        @if ($showTrash)
                            <flux:badge color="red">Eliminado</flux:badge>
                        @else
                            <flux:badge :color="$course->status === CourseStatus::Published ? 'green' : 'zinc'">
                                {{ $course->status->label() }}
                            </flux:badge>
                        @endif
                        <flux:text class="text-xs">{{ $course->created_at?->format('d/m/Y') }}</flux:text>
                    </div>

                    <flux:heading size="lg" class="mt-4">{{ $course->title }}</flux:heading>
                    <flux:text class="mt-2 line-clamp-2">{{ $course->summary ?? 'Sin resumen' }}</flux:text>

                    <div class="mt-5 flex items-center justify-between gap-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $course->estimated_duration_minutes !== null ? $course->estimated_duration_minutes.' minutos' : 'Duración pendiente' }}
                        </span>
                        @if ($showTrash)
                            <flux:button
                                wire:click="restore({{ $course->id }})"
                                wire:confirm="¿Restaurar este curso como borrador?"
                                variant="outline"
                                size="sm"
                                icon="arrow-path"
                                data-test="course-restore-button"
                            >
                                Restaurar
                            </flux:button>
                        @else
                            <flux:button
                                :href="route('admin.courses.edit', ['course' => $course])"
                                variant="ghost"
                                size="sm"
                                icon="pencil-square"
                                wire:navigate
                            >
                                Editar
                            </flux:button>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
