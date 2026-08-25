<?php

use App\Actions\Courses\AssignCourseToCollaborator;
use App\Actions\Courses\CancelCourseEnrollment;
use App\Enums\CourseAccessMode;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\MembershipStatus;
use App\Enums\TeamRole;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\Membership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Course $courseModel;

    public ?int $collaboratorId = null;

    public bool $isRequired = true;

    public string $dueDate = '';

    public ?int $reassigningEnrollmentId = null;

    public function mount(string $course): void
    {
        $team = Auth::user()->currentTeam;

        abort_if($team === null, 403);

        $this->courseModel = $team->courses()
            ->where('slug', $course)
            ->firstOrFail();

        Gate::authorize('update', $this->courseModel);
    }

    /** @return Collection<int, Membership> */
    #[Computed]
    public function collaborators(): Collection
    {
        return Membership::query()
            ->with('user')
            ->where('team_id', $this->courseModel->team_id)
            ->where('role', TeamRole::Member->value)
            ->where('status', MembershipStatus::Active->value)
            ->orderBy('user_id')
            ->get()
            ->sortBy(fn (Membership $membership): string => $membership->user->name)
            ->values();
    }

    /** @return Collection<int, CourseEnrollment> */
    #[Computed]
    public function enrollments(): Collection
    {
        return $this->courseModel->enrollments()
            ->with(['user', 'assigner', 'lastCanceller'])
            ->withCount([
                'lessonProgress as completed_published_lessons_count' => fn ($query) => $query
                    ->whereNotNull('completed_at')
                    ->whereHas('lesson', fn ($lessonQuery) => $lessonQuery
                        ->where('is_published', true)
                        ->whereHas('courseModule', fn ($moduleQuery) => $moduleQuery
                            ->where('course_id', $this->courseModel->id)
                            ->where('is_published', true))),
            ])
            ->latest('enrolled_at')
            ->get();
    }

    #[Computed]
    public function totalPublishedLessons(): int
    {
        return Lesson::query()
            ->where('is_published', true)
            ->whereHas('courseModule', fn ($query) => $query
                ->where('course_id', $this->courseModel->id)
                ->where('is_published', true))
            ->count();
    }

    public function assign(AssignCourseToCollaborator $assignCourse): void
    {
        $validated = $this->validate([
            'collaboratorId' => ['required', 'integer'],
            'isRequired' => ['boolean'],
            'dueDate' => ['nullable', 'date', 'after_or_equal:today'],
        ], [
            'collaboratorId.required' => 'Selecciona un colaborador.',
            'dueDate.after_or_equal' => 'La fecha límite no puede estar en el pasado.',
        ]);

        $administrator = Auth::user();
        $collaborator = User::query()->findOrFail($validated['collaboratorId']);
        $dueAt = filled($validated['dueDate'])
            ? CarbonImmutable::parse($validated['dueDate'])->endOfDay()
            : null;

        $enrollment = $assignCourse->handle(
            $administrator,
            $collaborator,
            $this->courseModel,
            $validated['isRequired'],
            $dueAt,
        );

        $enrollment->update(['roadmap_assignment_id' => null]);

        $this->reset('collaboratorId', 'dueDate', 'reassigningEnrollmentId');
        $this->isRequired = true;
        unset($this->enrollments);

        Flux::toast(variant: 'success', text: 'Curso asignado correctamente.');
    }

    public function cancel(int $enrollmentId, CancelCourseEnrollment $cancelEnrollment): void
    {
        $administrator = Auth::user();
        $enrollment = $this->findEnrollment($enrollmentId);

        $cancelEnrollment->handle($administrator, $enrollment);

        if ($this->reassigningEnrollmentId === $enrollmentId) {
            $this->cancelReassignment();
        }

        unset($this->enrollments);

        Flux::toast(variant: 'success', text: 'Inscripción cancelada. El progreso se conservó.');
    }

    public function prepareReassignment(int $enrollmentId): void
    {
        $enrollment = $this->findEnrollment($enrollmentId);

        abort_unless($enrollment->status === EnrollmentStatus::Cancelled, 422);

        $this->reassigningEnrollmentId = $enrollment->id;
        $this->collaboratorId = $enrollment->user_id;
        $this->isRequired = $enrollment->is_required;
        $this->dueDate = $enrollment->due_at?->isFuture()
            ? $enrollment->due_at->toDateString()
            : '';
    }

    public function cancelReassignment(): void
    {
        $this->reset('collaboratorId', 'dueDate', 'reassigningEnrollmentId');
        $this->isRequired = true;
    }

    private function findEnrollment(int $enrollmentId): CourseEnrollment
    {
        return $this->courseModel->enrollments()
            ->whereKey($enrollmentId)
            ->firstOrFail();
    }

    public function render()
    {
        return $this->view()->title('Asignaciones del curso');
    }
};
?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <header>
        <flux:button :href="route('admin.courses.index')" variant="ghost" icon="arrow-left" wire:navigate>
            Volver a cursos
        </flux:button>

        <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading size="xl">Asignaciones</flux:heading>
                <flux:subheading class="mt-1">{{ $courseModel->title }}</flux:subheading>
            </div>
            <flux:badge :color="$courseModel->status === CourseStatus::Published ? 'green' : 'zinc'">
                {{ $courseModel->status->label() }}
            </flux:badge>
        </div>
    </header>

    @if ($courseModel->status !== CourseStatus::Published)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/20 dark:text-amber-100">
            Publica el curso antes de asignarlo a los colaboradores.
        </div>
    @elseif ($courseModel->access_mode === CourseAccessMode::Blocked)
        <div class="rounded-xl border border-red-200 bg-red-50 p-5 text-red-900 dark:border-red-900/50 dark:bg-red-950/20 dark:text-red-100">
            Este curso está bloqueado. Cambia su acceso a “Sólo con asignación” antes de dar permiso a un colaborador.
        </div>
    @else
        <form wire:submit="assign" class="space-y-5 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div>
                <flux:heading size="lg">Nueva asignación</flux:heading>
                <flux:subheading class="mt-1">Asigna este curso a un colaborador activo de la organización.</flux:subheading>
            </div>

            @if ($this->collaborators->isEmpty())
                <flux:text>No hay colaboradores activos disponibles.</flux:text>
            @else
                @if ($reassigningEnrollmentId !== null)
                    <div class="flex flex-col gap-3 rounded-xl border border-blue-200 bg-blue-50 p-4 text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/20 dark:text-blue-100 sm:flex-row sm:items-center sm:justify-between">
                        <span>Estás preparando la reasignación de una inscripción cancelada.</span>
                        <flux:button type="button" variant="ghost" size="sm" wire:click="cancelReassignment">
                            Cancelar
                        </flux:button>
                    </div>
                @endif

                <div class="grid gap-5 md:grid-cols-2">
                    <flux:select wire:model="collaboratorId" label="Colaborador" required data-test="assignment-collaborator">
                        <flux:select.option value="">Selecciona un colaborador</flux:select.option>
                        @foreach ($this->collaborators as $membership)
                            <flux:select.option :value="$membership->user_id">
                                {{ $membership->user->name }} · {{ $membership->user->email }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input
                        wire:model="dueDate"
                        type="date"
                        label="Fecha límite"
                        description="Opcional"
                        min="{{ now()->toDateString() }}"
                        data-test="assignment-due-date"
                    />
                </div>

                <flux:checkbox
                    wire:model="isRequired"
                    label="Curso obligatorio"
                    description="Si lo desactivas, la asignación se marcará como opcional."
                    data-test="assignment-required"
                />

                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary" icon="user-plus" data-test="assignment-submit">
                        {{ $reassigningEnrollmentId !== null ? 'Reasignar curso' : 'Asignar curso' }}
                    </flux:button>
                </div>
            @endif
        </form>
    @endif

    <section class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div>
            <flux:heading size="lg">Inscripciones actuales</flux:heading>
            <flux:subheading class="mt-1">Asignaciones administrativas y registros voluntarios del curso.</flux:subheading>
        </div>

        @if ($this->enrollments->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
                <flux:text>Todavía no hay colaboradores inscritos.</flux:text>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($this->enrollments as $enrollment)
                    @php
                        $completedLessons = (int) ($enrollment->completed_published_lessons_count ?? 0);
                        $totalLessons = $this->totalPublishedLessons;
                        $progressPercentage = $enrollment->progressPercentage($completedLessons, $totalLessons);
                        $progressStatus = $enrollment->progressStatus($completedLessons, $totalLessons);
                    @endphp
                    <article wire:key="enrollment-{{ $enrollment->id }}" class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="font-semibold text-zinc-950 dark:text-white">{{ $enrollment->user->name }}</h3>
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $enrollment->user->email }}</p>
                                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                                    {{ $enrollment->assigner ? 'Asignado por '.$enrollment->assigner->name : 'Inscripción voluntaria' }}
                                </p>
                                @if ($enrollment->last_cancelled_at)
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                        Última cancelación: {{ $enrollment->last_cancelled_at->format('d/m/Y H:i') }}
                                        @if ($enrollment->lastCanceller)
                                            por {{ $enrollment->lastCanceller->name }}
                                        @endif
                                    </p>
                                @endif
                            </div>

                            <div class="flex flex-wrap gap-2 sm:justify-end">
                                <flux:badge :color="$enrollment->is_required ? 'amber' : 'blue'">
                                    {{ $enrollment->is_required ? 'Obligatorio' : 'Opcional' }}
                                </flux:badge>
                                <flux:badge :color="$progressStatus->color()">{{ $progressStatus->label() }}</flux:badge>
                                @if ($enrollment->due_at)
                                    <flux:badge color="purple">Límite: {{ $enrollment->due_at->format('d/m/Y') }}</flux:badge>
                                @endif
                            </div>
                        </div>

                        <div class="mt-5 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="text-zinc-600 dark:text-zinc-300">
                                    {{ $totalLessons > 0 ? $completedLessons.' de '.$totalLessons.' lecciones' : 'Sin lecciones publicadas' }}
                                </span>
                                <span class="font-semibold text-zinc-900 dark:text-white">{{ $progressPercentage }}%</span>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                <div class="h-full rounded-full bg-[#63b32e] transition-all" style="width: {{ $progressPercentage }}%"></div>
                            </div>

                            <div class="mt-4 flex justify-end gap-2">
                                @if ($enrollment->status === EnrollmentStatus::Active)
                                    <flux:button
                                        wire:click="cancel({{ $enrollment->id }})"
                                        wire:confirm="¿Cancelar esta inscripción? El progreso se conservará."
                                        variant="danger"
                                        size="sm"
                                        icon="x-circle"
                                        data-test="cancel-enrollment-{{ $enrollment->id }}"
                                    >
                                        Cancelar inscripción
                                    </flux:button>
                                @elseif ($enrollment->status === EnrollmentStatus::Cancelled)
                                    <flux:button
                                        wire:click="prepareReassignment({{ $enrollment->id }})"
                                        x-on:click="window.scrollTo({ top: 0, behavior: 'smooth' })"
                                        variant="primary"
                                        size="sm"
                                        icon="arrow-path"
                                        data-test="reassign-enrollment-{{ $enrollment->id }}"
                                    >
                                        Reasignar
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</section>
