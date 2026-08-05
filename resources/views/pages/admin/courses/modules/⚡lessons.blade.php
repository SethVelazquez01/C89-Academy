<?php

use App\Enums\LessonType;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public Course $courseModel;

    public CourseModule $courseModuleModel;

    public string $lessonTitle = '';

    public string $lessonType = 'text';

    public string $lessonContent = '';

    public string $lessonExternalUrl = '';

    public ?TemporaryUploadedFile $lessonDocument = null;

    public ?int $lessonEstimatedDurationMinutes = null;

    public ?int $editingLessonId = null;

    public string $editingLessonTitle = '';

    public string $editingLessonType = 'text';

    public string $editingLessonContent = '';

    public string $editingLessonExternalUrl = '';

    public ?TemporaryUploadedFile $editingLessonDocument = null;

    public ?int $editingLessonEstimatedDurationMinutes = null;

    public function mount(string $course, int $courseModule): void
    {
        $team = Auth::user()->currentTeam;

        abort_if($team === null, 403);

        $this->courseModel = $team->courses()
            ->where('slug', $course)
            ->firstOrFail();

        $this->courseModuleModel = $this->courseModel
            ->modules()
            ->whereKey($courseModule)
            ->firstOrFail();

        Gate::authorize('update', $this->courseModuleModel);
    }

    public function createLesson(): void
    {
        Gate::authorize('create', [Lesson::class, $this->courseModuleModel]);

        $validated = $this->validate([
            'lessonTitle' => ['required', 'string', 'max:255'],
            'lessonType' => [
                'required',
                Rule::in([
                    LessonType::Text->value,
                    LessonType::Video->value,
                    LessonType::Document->value,
                    LessonType::Link->value,
                ]),
            ],
            'lessonContent' => ['nullable', 'string', 'max:100000', 'required_if:lessonType,text'],
            'lessonExternalUrl' => ['nullable', 'url:http,https', 'max:2048', 'required_if:lessonType,video,link'],
            'lessonDocument' => ['nullable', 'file', 'mimes:pdf', 'max:20480', 'required_if:lessonType,document'],
            'lessonEstimatedDurationMinutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        $nextPosition = ((int) $this->courseModuleModel->lessons()->max('position')) + 1;
        $lessonType = LessonType::from($validated['lessonType']);
        $resource = null;

        if ($lessonType === LessonType::Document) {
            abort_unless($this->lessonDocument instanceof TemporaryUploadedFile, 422);
            $resource = $this->storeDocument($this->lessonDocument);
        }

        try {
            $this->courseModuleModel->lessons()->create([
                'title' => $validated['lessonTitle'],
                'type' => $lessonType,
                'content' => $lessonType === LessonType::Text ? $validated['lessonContent'] : null,
                'resource_disk' => $resource['disk'] ?? null,
                'resource_path' => $resource['path'] ?? null,
                'resource_name' => $resource['name'] ?? null,
                'resource_mime' => $resource['mime'] ?? null,
                'resource_size' => $resource['size'] ?? null,
                'external_url' => in_array($lessonType, [LessonType::Video, LessonType::Link], true)
                    ? $validated['lessonExternalUrl']
                    : null,
                'estimated_duration_minutes' => $validated['lessonEstimatedDurationMinutes'],
                'position' => $nextPosition,
                'is_published' => false,
            ]);
        } catch (\Throwable $exception) {
            if ($resource !== null) {
                $this->deleteStoredResource($resource['disk'], $resource['path']);
            }

            throw $exception;
        }

        $this->reset(
            'lessonTitle',
            'lessonType',
            'lessonContent',
            'lessonExternalUrl',
            'lessonDocument',
            'lessonEstimatedDurationMinutes',
        );

        $this->lessonType = LessonType::Text->value;

        Flux::toast(variant: 'success', text: 'Lección creada como borrador.');
    }

    public function startEditingLesson(int $lessonId): void
    {
        $lesson = $this->findLesson($lessonId);

        Gate::authorize('update', $lesson);

        $this->editingLessonId = $lesson->id;
        $this->editingLessonTitle = $lesson->title;
        $this->editingLessonType = $lesson->type->value;
        $this->editingLessonContent = $lesson->content ?? '';
        $this->editingLessonExternalUrl = $lesson->external_url ?? '';
        $this->editingLessonDocument = null;
        $this->editingLessonEstimatedDurationMinutes = $lesson->estimated_duration_minutes;
    }

    public function updateLesson(): void
    {
        abort_if($this->editingLessonId === null, 422);

        $lesson = $this->findLesson($this->editingLessonId);

        Gate::authorize('update', $lesson);

        $validated = $this->validate([
            'editingLessonTitle' => ['required', 'string', 'max:255'],
            'editingLessonType' => [
                'required',
                Rule::in([
                    LessonType::Text->value,
                    LessonType::Video->value,
                    LessonType::Document->value,
                    LessonType::Link->value,
                ]),
            ],
            'editingLessonContent' => ['nullable', 'string', 'max:100000', 'required_if:editingLessonType,text'],
            'editingLessonExternalUrl' => ['nullable', 'url:http,https', 'max:2048', 'required_if:editingLessonType,video,link'],
            'editingLessonDocument' => [
                Rule::requiredIf(
                    $this->editingLessonType === LessonType::Document->value
                        && blank($lesson->resource_path),
                ),
                'nullable',
                'file',
                'mimes:pdf',
                'max:20480',
            ],
            'editingLessonEstimatedDurationMinutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        $lessonType = LessonType::from($validated['editingLessonType']);
        $oldDisk = $lesson->resource_disk;
        $oldPath = $lesson->resource_path;
        $resource = null;

        if ($lessonType === LessonType::Document
            && $this->editingLessonDocument instanceof TemporaryUploadedFile) {
            $resource = $this->storeDocument($this->editingLessonDocument);
        }

        $resourceData = [
            'resource_disk' => null,
            'resource_path' => null,
            'resource_name' => null,
            'resource_mime' => null,
            'resource_size' => null,
        ];

        if ($lessonType === LessonType::Document) {
            $resourceData = $resource !== null
                ? [
                    'resource_disk' => $resource['disk'],
                    'resource_path' => $resource['path'],
                    'resource_name' => $resource['name'],
                    'resource_mime' => $resource['mime'],
                    'resource_size' => $resource['size'],
                ]
                : [
                    'resource_disk' => $lesson->resource_disk,
                    'resource_path' => $lesson->resource_path,
                    'resource_name' => $lesson->resource_name,
                    'resource_mime' => $lesson->resource_mime,
                    'resource_size' => $lesson->resource_size,
                ];
        }

        try {
            $lesson->update([
                'title' => $validated['editingLessonTitle'],
                'type' => $lessonType,
                'content' => $lessonType === LessonType::Text ? $validated['editingLessonContent'] : null,
                ...$resourceData,
                'external_url' => in_array($lessonType, [LessonType::Video, LessonType::Link], true)
                    ? $validated['editingLessonExternalUrl']
                    : null,
                'estimated_duration_minutes' => $validated['editingLessonEstimatedDurationMinutes'],
            ]);
        } catch (\Throwable $exception) {
            if ($resource !== null) {
                $this->deleteStoredResource($resource['disk'], $resource['path']);
            }

            throw $exception;
        }

        if (filled($oldDisk)
            && filled($oldPath)
            && ($lessonType !== LessonType::Document || $resource !== null)) {
            $this->deleteStoredResource((string) $oldDisk, (string) $oldPath);
        }

        $this->cancelEditingLesson();

        Flux::toast(variant: 'success', text: 'Lección actualizada correctamente.');
    }

    public function cancelEditingLesson(): void
    {
        $this->reset(
            'editingLessonId',
            'editingLessonTitle',
            'editingLessonType',
            'editingLessonContent',
            'editingLessonExternalUrl',
            'editingLessonDocument',
            'editingLessonEstimatedDurationMinutes',
        );

        $this->editingLessonType = LessonType::Text->value;
    }

    public function toggleLessonPublication(int $lessonId): void
    {
        $lesson = $this->findLesson($lessonId);

        Gate::authorize('update', $lesson);

        $lesson->update([
            'is_published' => ! $lesson->is_published,
        ]);

        Flux::toast(
            variant: 'success',
            text: $lesson->is_published
                ? 'Lección publicada.'
                : 'La lección volvió a borrador.',
        );
    }

    public function deleteLesson(int $lessonId): void
    {
        $lesson = $this->findLesson($lessonId);

        Gate::authorize('delete', $lesson);

        $lesson->delete();

        if ($this->editingLessonId === $lessonId) {
            $this->cancelEditingLesson();
        }

        Flux::toast(variant: 'success', text: 'Lección enviada a la papelera.');
    }

    private function findLesson(int $lessonId): Lesson
    {
        return $this->courseModuleModel
            ->lessons()
            ->whereKey($lessonId)
            ->firstOrFail();
    }

    /**
     * Store a PDF on the private local disk.
     *
     * @return array{disk: string, path: string, name: string, mime: string, size: int}
     */
    private function storeDocument(TemporaryUploadedFile $document): array
    {
        $directory = implode('/', [
            'course-resources',
            'team-'.$this->courseModel->team_id,
            'course-'.$this->courseModel->id,
            'module-'.$this->courseModuleModel->id,
        ]);
        $path = $document->storeAs(
            $directory,
            Str::uuid().'.pdf',
            'local',
        );

        if (! is_string($path)) {
            throw new \RuntimeException('No fue posible almacenar el documento.');
        }

        return [
            'disk' => 'local',
            'path' => $path,
            'name' => $document->getClientOriginalName(),
            'mime' => $document->getMimeType() ?? 'application/pdf',
            'size' => (int) $document->getSize(),
        ];
    }

    private function deleteStoredResource(string $disk, string $path): void
    {
        if (config("filesystems.disks.{$disk}") !== null) {
            Storage::disk($disk)->delete($path);
        }
    }

    public function render()
    {
        return $this->view([
            'lessons' => $this->courseModuleModel->lessons()->get(),
        ])->title('Lecciones del módulo');
    }
};
?>

<section class="mx-auto w-full max-w-5xl space-y-6">
    <header>
        <flux:button
            :href="route('admin.courses.edit', [
                'current_team' => $courseModel->team,
                'course' => $courseModel,
            ])"
            variant="ghost"
            icon="arrow-left"
            wire:navigate
        >
            Volver al curso
        </flux:button>

        <div class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ $courseModel->title }}</p>
                <flux:heading size="xl" class="mt-1">{{ $courseModuleModel->title }}</flux:heading>
                <flux:subheading class="mt-2">Crea y publica las lecciones que forman este módulo.</flux:subheading>
            </div>

            <flux:badge :color="$courseModuleModel->is_published ? 'green' : 'zinc'">
                Módulo {{ $courseModuleModel->is_published ? 'publicado' : 'en borrador' }}
            </flux:badge>
        </div>
    </header>

    <section class="space-y-5 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 md:p-8">
        <div>
            <flux:heading size="lg">Nueva lección</flux:heading>
            <flux:subheading class="mt-1">Agrega contenido escrito, un video por URL o un enlace externo.</flux:subheading>
        </div>

        <form wire:submit="createLesson" class="space-y-4 rounded-xl border border-dashed border-zinc-300 p-5 dark:border-zinc-700">
            <flux:input wire:model="lessonTitle" label="Título de la lección" required data-test="lesson-title-input" />

            <flux:select wire:model.live="lessonType" label="Tipo de lección" data-test="lesson-type-select">
                <flux:select.option value="text">Texto</flux:select.option>
                <flux:select.option value="video">Video por URL</flux:select.option>
                <flux:select.option value="document">Documento PDF</flux:select.option>
                <flux:select.option value="link">Enlace externo</flux:select.option>
            </flux:select>

            @if ($lessonType === LessonType::Text->value)
                <flux:textarea
                    wire:model="lessonContent"
                    label="Contenido"
                    description="Escribe el contenido inicial de la lección."
                    rows="8"
                    required
                    data-test="lesson-content-input"
                />
            @elseif ($lessonType === LessonType::Document->value)
                <flux:input
                    wire:model="lessonDocument"
                    label="Archivo PDF"
                    description="Máximo 20 MB. Se almacenará de forma privada."
                    type="file"
                    accept="application/pdf,.pdf"
                    required
                    data-test="lesson-document-input"
                />
                <p wire:loading wire:target="lessonDocument" class="text-sm text-zinc-500 dark:text-zinc-400">
                    Cargando documento…
                </p>
            @else
                <flux:input
                    wire:model="lessonExternalUrl"
                    label="{{ $lessonType === LessonType::Video->value ? 'URL del video' : 'URL del enlace' }}"
                    description="Utiliza una dirección segura que comience con https://"
                    type="url"
                    required
                    data-test="lesson-url-input"
                />
            @endif

            <flux:input
                wire:model.number="lessonEstimatedDurationMinutes"
                label="Duración estimada en minutos"
                type="number"
                min="1"
                max="1440"
                data-test="lesson-duration-input"
            />

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" icon="plus" data-test="lesson-create-button">
                    Agregar lección
                </flux:button>
            </div>
        </form>
    </section>

    <section class="space-y-4">
        <div class="flex items-end justify-between gap-4">
            <div>
                <flux:heading size="lg">Lecciones del módulo</flux:heading>
                <flux:subheading class="mt-1">{{ $lessons->count() }} lecciones registradas.</flux:subheading>
            </div>
        </div>

        @if ($lessons->isEmpty())
            <div class="rounded-2xl border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon name="document-text" class="mx-auto size-9 text-zinc-400" />
                <p class="mt-3 font-medium text-zinc-800 dark:text-zinc-200">Este módulo todavía no tiene lecciones.</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($lessons as $lesson)
                    <article wire:key="lesson-{{ $lesson->id }}" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                        @if ($editingLessonId === $lesson->id)
                            <form wire:submit="updateLesson" class="space-y-4">
                                <flux:input wire:model="editingLessonTitle" label="Título de la lección" required />
                                <flux:select wire:model.live="editingLessonType" label="Tipo de lección">
                                    <flux:select.option value="text">Texto</flux:select.option>
                                    <flux:select.option value="video">Video por URL</flux:select.option>
                                    <flux:select.option value="document">Documento PDF</flux:select.option>
                                    <flux:select.option value="link">Enlace externo</flux:select.option>
                                </flux:select>

                                @if ($editingLessonType === LessonType::Text->value)
                                    <flux:textarea wire:model="editingLessonContent" label="Contenido" rows="8" required />
                                @elseif ($editingLessonType === LessonType::Document->value)
                                    @if ($lesson->resource_path)
                                        <a
                                            href="{{ route('lessons.resource', [
                                                'current_team' => $courseModel->team,
                                                'course' => $courseModel,
                                                'courseModule' => $courseModuleModel,
                                                'lesson' => $lesson,
                                            ]) }}"
                                            class="inline-flex text-sm font-medium text-[#3d8d25] hover:underline dark:text-[#8bd15a]"
                                        >
                                            Descargar {{ $lesson->resource_name ?? 'documento actual' }}
                                        </a>
                                    @endif

                                    <flux:input
                                        wire:model="editingLessonDocument"
                                        label="{{ $lesson->resource_path ? 'Reemplazar PDF (opcional)' : 'Archivo PDF' }}"
                                        description="Máximo 20 MB."
                                        type="file"
                                        accept="application/pdf,.pdf"
                                        :required="! $lesson->resource_path"
                                    />
                                    <p wire:loading wire:target="editingLessonDocument" class="text-sm text-zinc-500 dark:text-zinc-400">
                                        Cargando documento…
                                    </p>
                                @else
                                    <flux:input
                                        wire:model="editingLessonExternalUrl"
                                        label="{{ $editingLessonType === LessonType::Video->value ? 'URL del video' : 'URL del enlace' }}"
                                        type="url"
                                        required
                                    />
                                @endif
                                <flux:input
                                    wire:model.number="editingLessonEstimatedDurationMinutes"
                                    label="Duración estimada en minutos"
                                    type="number"
                                    min="1"
                                    max="1440"
                                />
                                <div class="flex justify-end gap-2">
                                    <flux:button type="button" variant="ghost" wire:click="cancelEditingLesson">Cancelar</flux:button>
                                    <flux:button type="submit" variant="primary" icon="check">Guardar lección</flux:button>
                                </div>
                            </form>
                        @else
                            <div class="flex flex-col gap-5 md:flex-row md:items-start md:justify-between">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Lección {{ $lesson->position }}</span>
                                        <flux:badge color="blue">{{ $lesson->type->label() }}</flux:badge>
                                        <flux:badge :color="$lesson->is_published ? 'green' : 'zinc'">
                                            {{ $lesson->is_published ? 'Publicada' : 'Borrador' }}
                                        </flux:badge>
                                        @if ($lesson->estimated_duration_minutes)
                                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $lesson->estimated_duration_minutes }} min</span>
                                        @endif
                                    </div>

                                    <h3 class="mt-2 text-lg font-semibold text-zinc-950 dark:text-white">{{ $lesson->title }}</h3>
                                    @if ($lesson->type === LessonType::Text)
                                        <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ Str::limit($lesson->content ?? '', 180) }}</p>
                                    @elseif ($lesson->type === LessonType::Document)
                                        <a
                                            href="{{ route('lessons.resource', [
                                                'current_team' => $courseModel->team,
                                                'course' => $courseModel,
                                                'courseModule' => $courseModuleModel,
                                                'lesson' => $lesson,
                                            ]) }}"
                                            class="mt-2 inline-flex items-center gap-2 text-sm font-medium text-[#3d8d25] hover:underline dark:text-[#8bd15a]"
                                        >
                                            <flux:icon name="arrow-down-tray" class="size-4" />
                                            Descargar {{ $lesson->resource_name ?? 'documento PDF' }}
                                        </a>
                                    @else
                                        <a
                                            href="{{ $lesson->external_url }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="mt-2 inline-flex break-all text-sm font-medium text-[#3d8d25] hover:underline dark:text-[#8bd15a]"
                                        >
                                            {{ $lesson->external_url }}
                                        </a>
                                    @endif
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="startEditingLesson({{ $lesson->id }})">
                                        Editar
                                    </flux:button>
                                    <flux:button size="sm" variant="outline" wire:click="toggleLessonPublication({{ $lesson->id }})">
                                        {{ $lesson->is_published ? 'Volver a borrador' : 'Publicar' }}
                                    </flux:button>
                                    <flux:button
                                        size="sm"
                                        variant="danger"
                                        icon="trash"
                                        wire:click="deleteLesson({{ $lesson->id }})"
                                        wire:confirm="¿Enviar esta lección a la papelera?"
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
</section>
