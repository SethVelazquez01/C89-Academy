<?php

use App\Enums\ExamQuestionType;
use App\Enums\ExamSecurityLevel;
use App\Enums\ExamStatus;
use App\Models\Exam;
use App\Models\ExamQuestion;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component
{
    public Exam $exam;

    public string $activeTab = 'questions';

    public string $title = '';

    public string $summary = '';

    public string $instructions = '';

    public string $securityLevel = 'controlled';

    public int $timeLimitMinutes = 30;

    public int $passingScore = 80;

    public int $maxAttempts = 1;

    public bool $randomizeQuestions = true;

    public bool $randomizeOptions = true;

    public bool $allowBacktracking = false;

    public bool $fullscreenRequired = true;

    public ?int $editingQuestionId = null;

    public string $questionType = 'single_choice';

    public string $questionPrompt = '';

    /** @var array<int, string> */
    public array $questionOptions = ['', ''];

    public ?int $correctOptionIndex = null;

    public string $trueFalseAnswer = 'Verdadero';

    public int $questionPoints = 1;

    public function mount(Exam $exam): void
    {
        Gate::authorize('update', $exam);

        $this->exam = $exam;
        $this->fill([
            'title' => $exam->title,
            'summary' => $exam->summary ?? '',
            'instructions' => $exam->instructions ?? '',
            'securityLevel' => $exam->security_level->value,
            'timeLimitMinutes' => $exam->time_limit_minutes,
            'passingScore' => $exam->passing_score,
            'maxAttempts' => $exam->max_attempts,
            'randomizeQuestions' => $exam->randomize_questions,
            'randomizeOptions' => $exam->randomize_options,
            'allowBacktracking' => $exam->allow_backtracking,
            'fullscreenRequired' => $exam->fullscreen_required,
        ]);
    }

    public function showQuestions(): void
    {
        $this->activeTab = 'questions';
    }

    public function showSettings(): void
    {
        $this->activeTab = 'settings';
    }

    public function saveSettings(): void
    {
        Gate::authorize('update', $this->exam);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'securityLevel' => ['required', Rule::enum(ExamSecurityLevel::class)],
            'timeLimitMinutes' => ['required', 'integer', 'min:5', 'max:480'],
            'passingScore' => ['required', 'integer', 'min:1', 'max:100'],
            'maxAttempts' => ['required', 'integer', 'min:1', 'max:10'],
            'randomizeQuestions' => ['boolean'],
            'randomizeOptions' => ['boolean'],
            'allowBacktracking' => ['boolean'],
            'fullscreenRequired' => ['boolean'],
        ]);

        $this->exam->update([
            'title' => $validated['title'],
            'summary' => filled($validated['summary']) ? $validated['summary'] : null,
            'instructions' => filled($validated['instructions']) ? $validated['instructions'] : null,
            'security_level' => $validated['securityLevel'],
            'time_limit_minutes' => $validated['timeLimitMinutes'],
            'passing_score' => $validated['passingScore'],
            'max_attempts' => $validated['maxAttempts'],
            'randomize_questions' => $validated['randomizeQuestions'],
            'randomize_options' => $validated['randomizeOptions'],
            'allow_backtracking' => $validated['allowBacktracking'],
            'fullscreen_required' => $validated['fullscreenRequired'],
        ]);

        $this->exam->refresh();
        Flux::toast(variant: 'success', text: 'Configuración guardada.');
    }

    public function updatedQuestionType(): void
    {
        $this->correctOptionIndex = null;
        $this->trueFalseAnswer = 'Verdadero';

        if ($this->questionType === ExamQuestionType::SingleChoice->value && count($this->questionOptions) < 2) {
            $this->questionOptions = ['', ''];
        }
    }

    public function addQuestionOption(): void
    {
        if (count($this->questionOptions) < 10) {
            $this->questionOptions[] = '';
        }
    }

    public function removeQuestionOption(int $index): void
    {
        if (count($this->questionOptions) <= 2 || ! array_key_exists($index, $this->questionOptions)) {
            return;
        }

        array_splice($this->questionOptions, $index, 1);

        if ($this->correctOptionIndex === $index) {
            $this->correctOptionIndex = null;
        } elseif ($this->correctOptionIndex !== null && $this->correctOptionIndex > $index) {
            $this->correctOptionIndex--;
        }
    }

    public function editQuestion(int $questionId): void
    {
        Gate::authorize('update', $this->exam);

        $question = $this->exam->questions()->whereKey($questionId)->firstOrFail();

        $this->resetValidation();
        $this->editingQuestionId = $question->id;
        $this->questionType = $question->type->value;
        $this->questionPrompt = $question->prompt;
        $this->questionPoints = $question->points;
        $this->questionOptions = $question->options ?? ['', ''];
        $this->trueFalseAnswer = $question->type === ExamQuestionType::TrueFalse
            ? ($question->correct_answer ?? 'Verdadero')
            : 'Verdadero';

        $correctIndex = array_search($question->correct_answer, $this->questionOptions, true);
        $this->correctOptionIndex = $correctIndex === false ? null : $correctIndex;
    }

    public function cancelQuestionEditing(): void
    {
        $this->resetQuestionForm();
    }

    public function saveQuestion(): void
    {
        Gate::authorize('update', $this->exam);

        $validated = $this->validate([
            'questionType' => ['required', Rule::enum(ExamQuestionType::class)],
            'questionPrompt' => ['required', 'string', 'max:10000'],
            'questionOptions' => ['array', 'max:10'],
            'questionOptions.*' => ['nullable', 'string', 'max:1000'],
            'correctOptionIndex' => ['nullable', 'integer', 'min:0'],
            'trueFalseAnswer' => ['required', Rule::in(['Verdadero', 'Falso'])],
            'questionPoints' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $type = ExamQuestionType::from($validated['questionType']);
        $options = null;
        $correctAnswer = null;

        if ($type === ExamQuestionType::SingleChoice) {
            $rawOptions = array_map(fn (?string $option): string => trim($option ?? ''), $validated['questionOptions']);
            $options = array_values(array_unique(array_filter($rawOptions)));

            if (count($options) < 2) {
                throw ValidationException::withMessages(['questionOptions' => 'Agrega al menos dos opciones diferentes.']);
            }

            $selectedIndex = $validated['correctOptionIndex'];
            $correctAnswer = $selectedIndex !== null && array_key_exists($selectedIndex, $rawOptions)
                ? $rawOptions[$selectedIndex]
                : null;

            if ($correctAnswer === null || $correctAnswer === '' || ! in_array($correctAnswer, $options, true)) {
                throw ValidationException::withMessages(['correctOptionIndex' => 'Marca cuál opción es la respuesta correcta.']);
            }
        } elseif ($type === ExamQuestionType::TrueFalse) {
            $options = ['Verdadero', 'Falso'];
            $correctAnswer = $validated['trueFalseAnswer'];
        }

        DB::transaction(function () use ($validated, $type, $options, $correctAnswer): void {
            $attributes = [
                'type' => $type,
                'prompt' => $validated['questionPrompt'],
                'options' => $options,
                'correct_answer' => $correctAnswer,
                'explanation' => null,
                'points' => $validated['questionPoints'],
            ];

            if ($this->editingQuestionId !== null) {
                $this->exam->questions()->whereKey($this->editingQuestionId)->firstOrFail()->update($attributes);

                return;
            }

            $this->exam->questions()->create([
                ...$attributes,
                'position' => ((int) $this->exam->questions()->max('position')) + 1,
            ]);
        });

        $wasEditing = $this->editingQuestionId !== null;
        $this->resetQuestionForm();
        $this->exam->refresh();

        Flux::toast(variant: 'success', text: $wasEditing ? 'Pregunta actualizada.' : 'Pregunta agregada.');
    }

    public function deleteQuestion(int $questionId): void
    {
        Gate::authorize('update', $this->exam);

        $this->exam->questions()->whereKey($questionId)->firstOrFail()->delete();

        $this->exam->questions()->get()->values()->each(
            fn (ExamQuestion $question, int $index) => $question->update(['position' => $index + 1]),
        );

        if ($this->editingQuestionId === $questionId) {
            $this->resetQuestionForm();
        }

        $this->exam->refresh();
        Flux::toast(variant: 'success', text: 'Pregunta eliminada.');
    }

    public function togglePublished(): void
    {
        Gate::authorize('publish', $this->exam);

        if ($this->exam->status !== ExamStatus::Published && ! $this->exam->questions()->exists()) {
            $this->activeTab = 'questions';

            throw ValidationException::withMessages(['publication' => 'Agrega al menos una pregunta antes de publicar.']);
        }

        $isPublishing = $this->exam->status !== ExamStatus::Published;
        $this->exam->update([
            'status' => $isPublishing ? ExamStatus::Published : ExamStatus::Draft,
            'published_at' => $isPublishing ? now() : null,
        ]);
        $this->exam->refresh();

        Flux::toast(variant: 'success', text: $isPublishing ? 'Examen publicado.' : 'Examen devuelto a borrador.');
    }

    public function deleteExam(): void
    {
        Gate::authorize('delete', $this->exam);

        $team = $this->exam->team;
        $this->exam->delete();

        Flux::toast(variant: 'success', text: 'Examen enviado a la papelera.');
        $this->redirectRoute('admin.exams.index', ['current_team' => $team], navigate: true);
    }

    private function resetQuestionForm(): void
    {
        $this->reset('editingQuestionId', 'questionPrompt', 'correctOptionIndex');
        $this->questionType = ExamQuestionType::SingleChoice->value;
        $this->questionOptions = ['', ''];
        $this->trueFalseAnswer = 'Verdadero';
        $this->questionPoints = 1;
        $this->resetValidation();
    }
};
?>

<section class="mx-auto w-full max-w-5xl space-y-6" data-test="admin-exam-edit">
    <header class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <flux:button :href="route('admin.exams.index')" variant="ghost" icon="arrow-left" wire:navigate>Volver a exámenes</flux:button>
            <div class="mt-5 flex flex-wrap items-center gap-3">
                <flux:heading size="xl">{{ $exam->title }}</flux:heading>
                <flux:badge :color="$exam->status === ExamStatus::Published ? 'green' : 'zinc'">{{ $exam->status->label() }}</flux:badge>
            </div>
            <flux:subheading class="mt-1">Crea las preguntas como un formulario y publica cuando esté listo.</flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('admin.exams.attempts', ['exam' => $exam])" variant="ghost" icon="users" wire:navigate>Ver intentos</flux:button>
            <flux:button
                wire:click="togglePublished"
                :variant="$exam->status === ExamStatus::Published ? 'outline' : 'primary'"
                :icon="$exam->status === ExamStatus::Published ? 'lock-closed' : 'paper-airplane'"
            >
                {{ $exam->status === ExamStatus::Published ? 'Volver a borrador' : 'Publicar examen' }}
            </flux:button>
        </div>
    </header>

    @error('publication')
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ $message }}</div>
    @enderror

    <nav class="flex justify-center border-b border-zinc-200 dark:border-zinc-700" aria-label="Secciones del examen">
        <button
            type="button"
            wire:click="showQuestions"
            @class([
                'border-b-2 px-6 py-3 text-sm font-semibold transition',
                'border-[#63b32e] text-[#3d8d25] dark:text-[#8bd15a]' => $activeTab === 'questions',
                'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $activeTab !== 'questions',
            ])
        >
            Preguntas
        </button>
        <button
            type="button"
            wire:click="showSettings"
            @class([
                'border-b-2 px-6 py-3 text-sm font-semibold transition',
                'border-[#63b32e] text-[#3d8d25] dark:text-[#8bd15a]' => $activeTab === 'settings',
                'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $activeTab !== 'settings',
            ])
        >
            Configuración
        </button>
    </nav>

    @if ($activeTab === 'questions')
        <div class="mx-auto max-w-3xl space-y-4" data-test="exam-question-builder">
            <div class="flex items-center justify-between px-1">
                <div>
                    <h2 class="font-semibold text-zinc-950 dark:text-white">{{ $exam->questions()->count() }} preguntas</h2>
                    <p class="text-sm text-zinc-500">{{ $exam->questions()->sum('points') }} puntos totales</p>
                </div>
                <flux:badge color="blue">3 tipos disponibles</flux:badge>
            </div>

            @foreach ($exam->questions()->get() as $question)
                <article
                    @class([
                        'rounded-2xl border bg-white p-5 shadow-sm transition dark:bg-zinc-900',
                        'border-[#63b32e] ring-2 ring-[#63b32e]/20' => $editingQuestionId === $question->id,
                        'border-zinc-200 dark:border-zinc-700' => $editingQuestionId !== $question->id,
                    ])
                    wire:key="question-{{ $question->id }}"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="flex size-7 items-center justify-center rounded-full bg-[#63b32e]/15 text-xs font-bold text-[#3d8d25]">{{ $question->position }}</span>
                                <flux:badge color="zinc">{{ $question->type->label() }}</flux:badge>
                                <span class="text-xs text-zinc-500">{{ $question->points }} {{ $question->points === 1 ? 'punto' : 'puntos' }}</span>
                                @if ($question->type->requiresManualReview())
                                    <flux:badge color="amber">Revisión manual</flux:badge>
                                @endif
                            </div>

                            <p class="mt-4 text-base font-medium text-zinc-950 dark:text-white">{{ $question->prompt }}</p>

                            @if ($question->options)
                                <div class="mt-4 space-y-2">
                                    @foreach ($question->options as $option)
                                        <div class="flex items-center gap-3 text-sm">
                                            <span @class([
                                                'size-4 rounded-full border-2',
                                                'border-[#63b32e] bg-[#63b32e] ring-2 ring-[#63b32e]/20' => $option === $question->correct_answer,
                                                'border-zinc-300 dark:border-zinc-600' => $option !== $question->correct_answer,
                                            ])></span>
                                            <span>{{ $option }}</span>
                                            @if ($option === $question->correct_answer)<span class="text-xs font-semibold text-[#3d8d25] dark:text-[#8bd15a]">Correcta</span>@endif
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="mt-4 rounded-lg border-b border-dashed border-zinc-300 py-2 text-sm text-zinc-400 dark:border-zinc-600">El participante escribirá su respuesta aquí.</div>
                            @endif
                        </div>

                        <div class="flex shrink-0 gap-1">
                            <flux:button wire:click="editQuestion({{ $question->id }})" variant="ghost" size="sm" icon="pencil-square" aria-label="Editar pregunta {{ $question->position }}" />
                            <flux:button wire:click="deleteQuestion({{ $question->id }})" wire:confirm="¿Eliminar esta pregunta?" variant="ghost" size="sm" icon="trash" class="text-red-600" aria-label="Eliminar pregunta {{ $question->position }}" />
                        </div>
                    </div>
                </article>
            @endforeach

            <form wire:submit="saveQuestion" class="overflow-hidden rounded-2xl border border-zinc-200 border-t-4 border-t-[#63b32e] bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900" data-test="question-editor">
                <div class="space-y-5 p-5 sm:p-6">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <flux:heading size="lg">{{ $editingQuestionId === null ? 'Nueva pregunta' : 'Editar pregunta' }}</flux:heading>
                            <flux:subheading>Escribe el enunciado y define cómo se calificará.</flux:subheading>
                        </div>
                        <div class="w-full sm:w-56">
                            <flux:select wire:model.live="questionType" label="Tipo de pregunta">
                                @foreach (ExamQuestionType::cases() as $type)
                                    <flux:select.option :value="$type->value">{{ $type->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    </div>

                    <flux:textarea wire:model="questionPrompt" label="Pregunta" rows="3" placeholder="Escribe aquí la pregunta..." required />

                    @if ($questionType === ExamQuestionType::SingleChoice->value)
                        <fieldset class="space-y-3">
                            <legend class="text-sm font-medium">Opciones <span class="font-normal text-zinc-500">· marca la correcta</span></legend>
                            @foreach ($questionOptions as $index => $option)
                                <div class="flex items-center gap-3" wire:key="question-option-{{ $index }}">
                                    <input
                                        type="radio"
                                        wire:model="correctOptionIndex"
                                        value="{{ $index }}"
                                        class="size-4 shrink-0 accent-[#63b32e]"
                                        aria-label="Marcar opción {{ $index + 1 }} como correcta"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <flux:input wire:model="questionOptions.{{ $index }}" placeholder="Opción {{ $index + 1 }}" />
                                    </div>
                                    <flux:button
                                        type="button"
                                        wire:click="removeQuestionOption({{ $index }})"
                                        variant="ghost"
                                        size="sm"
                                        icon="x-mark"
                                        :disabled="count($questionOptions) <= 2"
                                        aria-label="Eliminar opción {{ $index + 1 }}"
                                    />
                                </div>
                            @endforeach
                            @error('questionOptions')<p class="text-sm font-medium text-red-500">{{ $message }}</p>@enderror
                            @error('correctOptionIndex')<p class="text-sm font-medium text-red-500">{{ $message }}</p>@enderror
                            <flux:button type="button" wire:click="addQuestionOption" variant="ghost" size="sm" icon="plus" :disabled="count($questionOptions) >= 10">Agregar opción</flux:button>
                        </fieldset>
                    @elseif ($questionType === ExamQuestionType::TrueFalse->value)
                        <fieldset class="space-y-3">
                            <legend class="text-sm font-medium">Respuesta correcta</legend>
                            @foreach (['Verdadero', 'Falso'] as $answer)
                                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-zinc-200 p-3 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800">
                                    <input type="radio" wire:model="trueFalseAnswer" value="{{ $answer }}" class="size-4 accent-[#63b32e]" />
                                    <span>{{ $answer }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    @else
                        <div class="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                            <flux:icon name="pencil-square" class="mt-0.5 size-5 shrink-0" />
                            <div><p class="font-medium">Respuesta abierta</p><p class="mt-1 text-sm">No necesita respuesta correcta. Quedará pendiente para revisión manual cuando el participante entregue el examen.</p></div>
                        </div>
                    @endif
                </div>

                <div class="flex flex-col gap-3 border-t border-zinc-200 bg-zinc-50 px-5 py-4 dark:border-zinc-700 dark:bg-zinc-800/50 sm:flex-row sm:items-end sm:justify-between">
                    <div class="w-full sm:w-32">
                        <flux:input wire:model.number="questionPoints" type="number" min="1" max="100" label="Puntos" required />
                    </div>
                    <div class="flex justify-end gap-2">
                        @if ($editingQuestionId !== null)
                            <flux:button type="button" wire:click="cancelQuestionEditing" variant="ghost">Cancelar edición</flux:button>
                        @endif
                        <flux:button type="submit" variant="primary" icon="check">
                            {{ $editingQuestionId === null ? 'Agregar pregunta' : 'Guardar cambios' }}
                        </flux:button>
                    </div>
                </div>
            </form>
        </div>
    @else
        <div class="mx-auto max-w-3xl space-y-6" data-test="exam-settings">
            <form wire:submit="saveSettings" class="space-y-6 rounded-2xl border border-zinc-200 border-t-4 border-t-[#63b32e] bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div><flux:heading size="lg">Datos del examen</flux:heading><flux:subheading>Información y reglas que verá el participante.</flux:subheading></div>
                <flux:input wire:model="title" label="Título" required />
                <flux:textarea wire:model="summary" label="Descripción" rows="3" />
                <flux:textarea wire:model="instructions" label="Instrucciones" rows="4" placeholder="Materiales permitidos, recomendaciones y reglas." />
                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:input wire:model.number="timeLimitMinutes" type="number" min="5" max="480" label="Duración (minutos)" />
                    <flux:input wire:model.number="passingScore" type="number" min="1" max="100" label="Aprobación (%)" />
                    <flux:input wire:model.number="maxAttempts" type="number" min="1" max="10" label="Intentos" />
                </div>

                <div class="border-t border-zinc-200 pt-6 dark:border-zinc-700"><flux:heading>Seguridad</flux:heading><flux:subheading class="mt-1">Controles que usará el futuro motor de intentos protegidos.</flux:subheading></div>
                <flux:select wire:model="securityLevel" label="Nivel de seguridad">
                    @foreach (ExamSecurityLevel::cases() as $level)
                        <flux:select.option :value="$level->value">{{ $level->label() }} — {{ $level->description() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:switch wire:model="randomizeQuestions" label="Aleatorizar preguntas" />
                    <flux:switch wire:model="randomizeOptions" label="Aleatorizar respuestas" />
                    <flux:switch wire:model="fullscreenRequired" label="Solicitar pantalla completa" />
                    <flux:switch wire:model="allowBacktracking" label="Permitir regresar" />
                </div>
                <div class="flex justify-end"><flux:button type="submit" variant="primary" icon="check">Guardar configuración</flux:button></div>
            </form>

            <section class="flex flex-col gap-4 rounded-2xl border border-red-200 bg-red-50 p-5 dark:border-red-900 dark:bg-red-950/30 sm:flex-row sm:items-center sm:justify-between" data-test="delete-exam-section">
                <div><h2 class="font-semibold text-red-900 dark:text-red-100">Eliminar examen</h2><p class="mt-1 text-sm text-red-700 dark:text-red-300">Se enviará a la papelera y podrás restaurarlo después.</p></div>
                <flux:button wire:click="deleteExam" wire:confirm="¿Enviar este examen a la papelera?" variant="danger" class="shrink-0">Eliminar examen</flux:button>
            </section>
        </div>
    @endif
</section>
