<?php

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\CourseModule;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public Course $courseModel;

    public string $title = '';

    public string $summary = '';

    public string $description = '';

    public ?int $estimatedDurationMinutes = null;

    public string $moduleTitle = '';

    public string $moduleDescription = '';

    public ?int $editingModuleId = null;

    public string $editingModuleTitle = '';

    public string $editingModuleDescription = '';

    public function mount(string $course): void
    {
        $team = Auth::user()->currentTeam;

        abort_if($team === null, 403);

        $this->courseModel = $team->courses()
            ->where('slug', $course)
            ->firstOrFail();

        Gate::authorize('update', $this->courseModel);

        $this->title = $this->courseModel->title;
        $this->summary = $this->courseModel->summary ?? '';
        $this->description = $this->courseModel->description ?? '';
        $this->estimatedDurationMinutes = $this->courseModel->estimated_duration_minutes;
    }

    public function save(): void
    {
        Gate::authorize('update', $this->courseModel);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'estimatedDurationMinutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
        ]);

        $this->courseModel->update([
            'title' => $validated['title'],
            'summary' => filled($validated['summary']) ? $validated['summary'] : null,
            'description' => filled($validated['description']) ? $validated['description'] : null,
            'estimated_duration_minutes' => $validated['estimatedDurationMinutes'],
        ]);

        Flux::toast(variant: 'success', text: 'Curso actualizado correctamente.');

        $this->redirectRoute(
            'admin.courses.index',
            ['current_team' => $this->courseModel->team],
            navigate: true,
        );
    }

    public function createModule(): void
    {
        Gate::authorize('create', [CourseModule::class, $this->courseModel]);

        $validated = $this->validate([
            'moduleTitle' => ['required', 'string', 'max:255'],
            'moduleDescription' => ['nullable', 'string', 'max:2000'],
        ]);

        $nextPosition = ((int) $this->courseModel->modules()->max('position')) + 1;

        $this->courseModel->modules()->create([
            'title' => $validated['moduleTitle'],
            'description' => filled($validated['moduleDescription'])
                ? $validated['moduleDescription']
                : null,
            'position' => $nextPosition,
            'is_published' => false,
        ]);

        $this->reset('moduleTitle', 'moduleDescription');

        Flux::toast(variant: 'success', text: 'Módulo creado como borrador.');
    }

    public function startEditingModule(int $moduleId): void
    {
        $courseModule = $this->findModule($moduleId);

        Gate::authorize('update', $courseModule);

        $this->editingModuleId = $courseModule->id;
        $this->editingModuleTitle = $courseModule->title;
        $this->editingModuleDescription = $courseModule->description ?? '';
    }

    public function updateModule(): void
    {
        abort_if($this->editingModuleId === null, 422);

        $courseModule = $this->findModule($this->editingModuleId);

        Gate::authorize('update', $courseModule);

        $validated = $this->validate([
            'editingModuleTitle' => ['required', 'string', 'max:255'],
            'editingModuleDescription' => ['nullable', 'string', 'max:2000'],
        ]);

        $courseModule->update([
            'title' => $validated['editingModuleTitle'],
            'description' => filled($validated['editingModuleDescription'])
                ? $validated['editingModuleDescription']
                : null,
        ]);

        $this->cancelEditingModule();

        Flux::toast(variant: 'success', text: 'Módulo actualizado correctamente.');
    }

    public function cancelEditingModule(): void
    {
        $this->reset(
            'editingModuleId',
            'editingModuleTitle',
            'editingModuleDescription',
        );
    }

    public function toggleModulePublication(int $moduleId): void
    {
        $courseModule = $this->findModule($moduleId);

        Gate::authorize('update', $courseModule);

        $courseModule->update([
            'is_published' => ! $courseModule->is_published,
        ]);

        Flux::toast(
            variant: 'success',
            text: $courseModule->is_published
                ? 'Módulo publicado.'
                : 'El módulo volvió a borrador.',
        );
    }

    public function deleteModule(int $moduleId): void
    {
        $courseModule = $this->findModule($moduleId);

        Gate::authorize('delete', $courseModule);

        $courseModule->delete();

        if ($this->editingModuleId === $moduleId) {
            $this->cancelEditingModule();
        }

        Flux::toast(variant: 'success', text: 'Módulo enviado a la papelera.');
    }

    public function publish(): void
    {
        Gate::authorize('publish', $this->courseModel);

        abort_unless($this->courseModel->status === CourseStatus::Draft, 422);

        $this->courseModel->update([
            'status' => CourseStatus::Published,
            'published_at' => now(),
        ]);

        $this->courseModel->refresh();

        Flux::toast(variant: 'success', text: 'Curso marcado como publicado.');
    }

    public function unpublish(): void
    {
        Gate::authorize('publish', $this->courseModel);

        abort_unless($this->courseModel->status === CourseStatus::Published, 422);

        $this->courseModel->update([
            'status' => CourseStatus::Draft,
            'published_at' => null,
        ]);

        $this->courseModel->refresh();

        Flux::toast(variant: 'success', text: 'El curso volvió a borrador.');
    }

    public function moveToTrash(): void
    {
        Gate::authorize('delete', $this->courseModel);

        $team = $this->courseModel->team;

        $this->courseModel->delete();

        Flux::toast(variant: 'success', text: 'Curso enviado a la papelera.');

        $this->redirectRoute(
            'admin.courses.index',
            ['current_team' => $team],
            navigate: true,
        );
    }

    private function findModule(int $moduleId): CourseModule
    {
        return $this->courseModel->modules()
            ->whereKey($moduleId)
            ->firstOrFail();
    }

    public function render()
    {
        return $this->view([
            'courseModules' => $this->courseModel
                ->modules()
                ->withCount('lessons')
                ->get(),
        ])->title('Editar curso');
    }
};
?>

<section class="mx-auto w-full max-w-5xl space-y-6">
    <header>
        <flux:button :href="route('admin.courses.index')" variant="ghost" icon="arrow-left" wire:navigate>
            Volver a cursos
        </flux:button>

        <div class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading size="xl">Editar curso</flux:heading>
                <flux:subheading class="mt-1">Actualiza la información y organiza el contenido del curso.</flux:subheading>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <flux:badge :color="$courseModel->status === CourseStatus::Published ? 'green' : 'zinc'">
                    {{ $courseModel->status->label() }}
                </flux:badge>

                @if ($courseModel->status === CourseStatus::Draft)
                    <flux:button
                        wire:click="publish"
                        wire:confirm="¿Publicar este curso para los colaboradores?"
                        variant="primary"
                        size="sm"
                        icon="rocket-launch"
                        data-test="course-publish-button"
                    >
                        Publicar
                    </flux:button>
                @elseif ($courseModel->status === CourseStatus::Published)
                    <flux:button
                        wire:click="unpublish"
                        wire:confirm="¿Devolver este curso a borrador?"
                        variant="outline"
                        size="sm"
                        icon="arrow-uturn-left"
                        data-test="course-unpublish-button"
                    >
                        Volver a borrador
                    </flux:button>
                @endif
            </div>
        </div>
    </header>

    <form wire:submit="save" class="space-y-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 md:p-8">
        <div>
            <flux:heading size="lg">Información general</flux:heading>
            <flux:subheading class="mt-1">Datos que verá el colaborador en el catálogo.</flux:subheading>
        </div>

        <flux:input wire:model="title" label="Título del curso" required autofocus data-test="course-title-input" />

        <flux:textarea
            wire:model="summary"
            label="Resumen corto"
            description="Una explicación breve que aparecerá en la tarjeta del curso."
            rows="3"
            data-test="course-summary-input"
        />

        <flux:textarea wire:model="description" label="Descripción" rows="6" data-test="course-description-input" />

        <flux:input
            wire:model.number="estimatedDurationMinutes"
            label="Duración estimada en minutos"
            type="number"
            min="1"
            max="10080"
            data-test="course-duration-input"
        />

        <div class="flex flex-col-reverse gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700 sm:flex-row sm:justify-end">
            <flux:button :href="route('admin.courses.index')" variant="ghost" wire:navigate>
                Cancelar
            </flux:button>
            <flux:button variant="primary" type="submit" icon="document-check" data-test="course-save-button">
                Guardar cambios
            </flux:button>
        </div>
    </form>

    <section class="space-y-5 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 md:p-8">
        <div>
            <flux:heading size="lg">Contenido del curso</flux:heading>
            <flux:subheading class="mt-1">Divide el curso en módulos. Después agregaremos sus lecciones.</flux:subheading>
        </div>

        <form wire:submit="createModule" class="space-y-4 rounded-xl border border-dashed border-zinc-300 p-5 dark:border-zinc-700">
            <flux:heading>Nuevo módulo</flux:heading>
            <flux:input wire:model="moduleTitle" label="Título del módulo" required data-test="module-title-input" />
            <flux:textarea wire:model="moduleDescription" label="Descripción opcional" rows="2" data-test="module-description-input" />
            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" icon="plus" data-test="module-create-button">
                    Agregar módulo
                </flux:button>
            </div>
        </form>

        @if ($courseModules->isEmpty())
            <div class="rounded-xl border border-zinc-200 p-6 text-center dark:border-zinc-700">
                <flux:icon name="rectangle-stack" class="mx-auto size-8 text-zinc-400" />
                <p class="mt-3 font-medium text-zinc-800 dark:text-zinc-200">Este curso todavía no tiene módulos.</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($courseModules as $courseModule)
                    <article wire:key="module-{{ $courseModule->id }}" class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                        @if ($editingModuleId === $courseModule->id)
                            <form wire:submit="updateModule" class="space-y-4">
                                <flux:input wire:model="editingModuleTitle" label="Título del módulo" required data-test="editing-module-title-input" />
                                <flux:textarea wire:model="editingModuleDescription" label="Descripción opcional" rows="2" />
                                <div class="flex justify-end gap-2">
                                    <flux:button type="button" variant="ghost" wire:click="cancelEditingModule">Cancelar</flux:button>
                                    <flux:button type="submit" variant="primary" icon="check" data-test="module-update-button">Guardar módulo</flux:button>
                                </div>
                            </form>
                        @else
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Módulo {{ $courseModule->position }}</span>
                                        <flux:badge :color="$courseModule->is_published ? 'green' : 'zinc'">
                                            {{ $courseModule->is_published ? 'Publicado' : 'Borrador' }}
                                        </flux:badge>
                                        <flux:badge color="blue">{{ $courseModule->lessons_count }} lecciones</flux:badge>
                                    </div>
                                    <h3 class="mt-2 text-lg font-semibold text-zinc-950 dark:text-white">{{ $courseModule->title }}</h3>
                                    @if ($courseModule->description)
                                        <p class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ $courseModule->description }}</p>
                                    @endif
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <flux:button
                                        :href="route('admin.courses.modules.lessons', [
                                            'current_team' => $courseModel->team,
                                            'course' => $courseModel,
                                            'courseModule' => $courseModule,
                                        ])"
                                        size="sm"
                                        variant="primary"
                                        icon="book-open"
                                        wire:navigate
                                    >
                                        Gestionar lecciones
                                    </flux:button>
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="startEditingModule({{ $courseModule->id }})">
                                        Editar
                                    </flux:button>
                                    <flux:button size="sm" variant="outline" wire:click="toggleModulePublication({{ $courseModule->id }})">
                                        {{ $courseModule->is_published ? 'Volver a borrador' : 'Publicar' }}
                                    </flux:button>
                                    <flux:button
                                        size="sm"
                                        variant="danger"
                                        icon="trash"
                                        wire:click="deleteModule({{ $courseModule->id }})"
                                        wire:confirm="¿Enviar este módulo a la papelera?"
                                    >
                                        Eliminar
                                    </flux:button>
                                </div>
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <aside class="rounded-2xl border border-red-200 bg-red-50 p-6 dark:border-red-900/50 dark:bg-red-950/20">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading>Enviar a la papelera</flux:heading>
                <flux:text class="mt-1">El curso dejará de aparecer en el catálogo, pero podrás restaurarlo.</flux:text>
            </div>
            <flux:button
                wire:click="moveToTrash"
                wire:confirm="¿Enviar este curso a la papelera?"
                variant="danger"
                icon="trash"
                data-test="course-delete-button"
            >
                Eliminar curso
            </flux:button>
        </div>
    </aside>
</section>
