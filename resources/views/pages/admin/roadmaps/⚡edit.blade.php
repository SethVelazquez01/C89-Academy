<?php

use App\Enums\CourseAccessMode;
use App\Enums\CourseStatus;
use App\Enums\ExamStatus;
use App\Enums\RoadmapStatus;
use App\Models\Course;
use App\Models\Exam;
use App\Models\Roadmap;
use App\Models\RoadmapItem;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Roadmap $roadmapModel;

    public string $title = '';

    public string $summary = '';

    public string $itemType = 'course';

    public ?int $contentId = null;

    public function mount(string $roadmap): void
    {
        $team = Auth::user()->currentTeam;
        abort_if($team === null, 403);

        $this->roadmapModel = $team->roadmaps()->where('slug', $roadmap)->firstOrFail();
        Gate::authorize('update', $this->roadmapModel);

        $this->title = $this->roadmapModel->title;
        $this->summary = $this->roadmapModel->summary ?? '';
    }

    /** @return Collection<int, Course> */
    #[Computed]
    public function availableCourses(): Collection
    {
        return $this->roadmapModel->team->courses()
            ->whereDoesntHave('roadmapItems', fn ($query) => $query->where('roadmap_id', $this->roadmapModel->id))
            ->orderBy('title')
            ->get();
    }

    /** @return Collection<int, Exam> */
    #[Computed]
    public function availableExams(): Collection
    {
        return $this->roadmapModel->team->exams()
            ->whereDoesntHave('roadmapItems', fn ($query) => $query->where('roadmap_id', $this->roadmapModel->id))
            ->orderBy('title')
            ->get();
    }

    /** @return Collection<int, RoadmapItem> */
    #[Computed]
    public function items(): Collection
    {
        return $this->roadmapModel->items()->with(['course', 'exam'])->get();
    }

    public function updatedItemType(): void
    {
        $this->reset('contentId');
    }

    public function save(): void
    {
        Gate::authorize('update', $this->roadmapModel);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->roadmapModel->update([
            'title' => $validated['title'],
            'summary' => filled($validated['summary']) ? $validated['summary'] : null,
        ]);

        Flux::toast(variant: 'success', text: 'Información actualizada.');
    }

    public function addItem(): void
    {
        Gate::authorize('update', $this->roadmapModel);

        $validated = $this->validate([
            'itemType' => ['required', Rule::in(['course', 'exam'])],
            'contentId' => ['required', 'integer'],
        ], ['contentId.required' => 'Selecciona el contenido que deseas agregar.']);

        $attribute = $validated['itemType'] === 'course' ? 'course_id' : 'exam_id';
        $query = $validated['itemType'] === 'course'
            ? $this->roadmapModel->team->courses()
            : $this->roadmapModel->team->exams();
        $content = $query->whereKey($validated['contentId'])->firstOrFail();

        if ($this->roadmapModel->items()->where($attribute, $content->id)->exists()) {
            throw ValidationException::withMessages(['contentId' => ['Ese contenido ya forma parte del roadmap.']]);
        }

        $this->roadmapModel->items()->create([
            $attribute => $content->id,
            'position' => ((int) $this->roadmapModel->items()->max('position')) + 1,
        ]);

        $this->reset('contentId');
        unset($this->items, $this->availableCourses, $this->availableExams);
        Flux::toast(variant: 'success', text: 'Paso agregado al roadmap.');
    }

    public function moveItem(int $itemId, string $direction): void
    {
        Gate::authorize('update', $this->roadmapModel);
        abort_unless(in_array($direction, ['up', 'down'], true), 422);

        $item = $this->findItem($itemId);
        $comparison = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'desc' : 'asc';
        $neighbor = $this->roadmapModel->items()
            ->where('position', $comparison, $item->position)
            ->orderBy('position', $order)
            ->first();

        if ($neighbor === null) {
            return;
        }

        DB::transaction(function () use ($item, $neighbor): void {
            $originalPosition = $item->position;
            $temporaryPosition = ((int) $this->roadmapModel->items()->max('position')) + 1;
            $item->update(['position' => $temporaryPosition]);
            $neighbor->update(['position' => $originalPosition]);
            $item->update(['position' => $neighbor->getOriginal('position')]);
        });

        unset($this->items);
    }

    public function removeItem(int $itemId): void
    {
        Gate::authorize('update', $this->roadmapModel);
        $this->findItem($itemId)->delete();

        foreach ($this->roadmapModel->items()->orderBy('position')->get() as $index => $item) {
            $item->update(['position' => $index + 1]);
        }

        unset($this->items, $this->availableCourses, $this->availableExams);
        Flux::toast(variant: 'success', text: 'Paso retirado.');
    }

    public function publish(): void
    {
        Gate::authorize('publish', $this->roadmapModel);

        if ($this->roadmapModel->items()->doesntExist()) {
            throw ValidationException::withMessages(['roadmap' => ['Agrega al menos un curso o examen antes de publicar.']]);
        }

        if ($this->roadmapModel->courses()->where(function ($query) {
            $query->where('status', '!=', CourseStatus::Published)
                ->orWhere('access_mode', CourseAccessMode::Blocked);
        })->exists()) {
            throw ValidationException::withMessages(['roadmap' => ['Todos los cursos deben estar publicados y no bloqueados.']]);
        }

        if ($this->roadmapModel->exams()->where('status', '!=', ExamStatus::Published)->exists()) {
            throw ValidationException::withMessages(['roadmap' => ['Todos los exámenes deben estar publicados.']]);
        }

        $this->roadmapModel->update(['status' => RoadmapStatus::Published, 'published_at' => now()]);
        $this->roadmapModel->refresh();
        Flux::toast(variant: 'success', text: 'Roadmap publicado y listo para asignarse.');
    }

    public function unpublish(): void
    {
        Gate::authorize('publish', $this->roadmapModel);
        $this->roadmapModel->update(['status' => RoadmapStatus::Draft, 'published_at' => null]);
        $this->roadmapModel->refresh();
        Flux::toast(variant: 'success', text: 'El roadmap volvió a borrador.');
    }

    public function moveToTrash(): void
    {
        Gate::authorize('delete', $this->roadmapModel);
        $team = $this->roadmapModel->team;
        $this->roadmapModel->delete();
        $this->redirectRoute('admin.roadmaps.index', ['current_team' => $team], navigate: true);
    }

    private function findItem(int $itemId): RoadmapItem
    {
        return $this->roadmapModel->items()->whereKey($itemId)->firstOrFail();
    }

    public function render()
    {
        return $this->view()->title('Configurar roadmap');
    }
};
?>

<section class="mx-auto w-full max-w-5xl space-y-6">
    <header>
        <flux:button :href="route('admin.roadmaps.index')" variant="ghost" icon="arrow-left" wire:navigate>Volver a roadmaps</flux:button>
        <div class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div><flux:heading size="xl">Configurar roadmap</flux:heading><flux:subheading class="mt-1">Crea un camino simple, de arriba hacia abajo.</flux:subheading></div>
            <div class="flex flex-wrap gap-2">
                <flux:badge :color="$roadmapModel->status === RoadmapStatus::Published ? 'green' : 'zinc'">{{ $roadmapModel->status->label() }}</flux:badge>
                @if ($roadmapModel->status === RoadmapStatus::Published)
                    <flux:button wire:click="unpublish" wire:confirm="¿Volver este roadmap a borrador?" variant="outline" size="sm">Volver a borrador</flux:button>
                @else
                    <flux:button wire:click="publish" variant="primary" size="sm" icon="rocket-launch" data-test="roadmap-publish">Publicar</flux:button>
                @endif
            </div>
        </div>
    </header>

    @error('roadmap') <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-100">{{ $message }}</div> @enderror

    <form wire:submit="save" class="space-y-5 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div><flux:heading size="lg">1. Información general</flux:heading><flux:subheading class="mt-1">El nombre que verán los colaboradores asignados.</flux:subheading></div>
        <flux:input wire:model="title" label="Nombre" required />
        <flux:textarea wire:model="summary" label="Descripción breve" rows="3" />
        <div class="flex justify-end"><flux:button type="submit" variant="primary" icon="check">Guardar</flux:button></div>
    </form>

    <section class="space-y-5 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div><flux:heading size="lg">2. Pasos del camino</flux:heading><flux:subheading class="mt-1">Agrega cursos y exámenes en el orden correcto.</flux:subheading></div>
        <form wire:submit="addItem" class="grid gap-4 rounded-xl border border-dashed border-zinc-300 p-5 dark:border-zinc-700 md:grid-cols-[12rem_1fr_auto] md:items-end">
            <flux:select wire:model.live="itemType" label="Tipo">
                <flux:select.option value="course">Curso</flux:select.option>
                <flux:select.option value="exam">Examen</flux:select.option>
            </flux:select>
            <flux:select wire:model="contentId" :label="$itemType === 'course' ? 'Curso' : 'Examen'" required data-test="roadmap-content-select">
                <flux:select.option value="">Selecciona una opción</flux:select.option>
                @foreach ($itemType === 'course' ? $this->availableCourses : $this->availableExams as $content)
                    <flux:select.option :value="$content->id">{{ $content->title }} · {{ $content->status->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button type="submit" variant="primary" icon="plus" data-test="roadmap-add-item">Agregar</flux:button>
        </form>

        @if ($this->items->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700"><flux:text>La ruta está vacía. Agrega el primer paso.</flux:text></div>
        @else
            <div class="space-y-3">
                @foreach ($this->items as $item)
                    @php($content = $item->isCourse() ? $item->course : $item->exam)
                    <article class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700 sm:flex-row sm:items-center" wire:key="roadmap-item-{{ $item->id }}">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-[#63b32e] font-bold text-white">{{ $item->position }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap gap-2"><flux:badge color="blue">{{ $item->isCourse() ? 'Curso' : 'Examen' }}</flux:badge><flux:badge :color="$content?->status === CourseStatus::Published || $content?->status === ExamStatus::Published ? 'green' : 'zinc'">{{ $content?->status->label() ?? 'No disponible' }}</flux:badge></div>
                            <p class="mt-2 truncate font-semibold text-zinc-950 dark:text-white">{{ $content?->title ?? 'Contenido eliminado' }}</p>
                        </div>
                        <div class="flex gap-1 sm:justify-end">
                            <flux:button wire:click="moveItem({{ $item->id }}, 'up')" variant="ghost" size="sm" icon="chevron-up" aria-label="Subir paso" />
                            <flux:button wire:click="moveItem({{ $item->id }}, 'down')" variant="ghost" size="sm" icon="chevron-down" aria-label="Bajar paso" />
                            <flux:button wire:click="removeItem({{ $item->id }})" wire:confirm="¿Retirar este paso?" variant="danger" size="sm" icon="trash" aria-label="Retirar paso" />
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="rounded-2xl border border-[#63b32e]/40 bg-[#63b32e]/10 p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div><flux:heading>3. Asignar a colaboradores</flux:heading><flux:text class="mt-1">Cuando esté publicado, elige quién debe seguir esta ruta.</flux:text></div>
            <flux:button :href="route('admin.roadmaps.assignments', ['roadmap' => $roadmapModel])" variant="primary" icon="user-plus" wire:navigate>Ir a asignaciones</flux:button>
        </div>
    </section>

    <aside class="rounded-2xl border border-red-200 bg-red-50 p-6 dark:border-red-900/50 dark:bg-red-950/20">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><div><flux:heading>Enviar a la papelera</flux:heading><flux:text class="mt-1">Dejará de estar disponible para nuevas asignaciones.</flux:text></div><flux:button wire:click="moveToTrash" wire:confirm="¿Enviar este roadmap a la papelera?" variant="danger" icon="trash">Eliminar roadmap</flux:button></div>
    </aside>
</section>
