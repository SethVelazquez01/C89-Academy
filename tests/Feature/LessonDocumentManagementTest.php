<?php

use App\Enums\LessonType;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\C89OrganizationSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');

    $this->seed(C89OrganizationSeeder::class);

    $this->team = Team::query()->where('slug', 'c89')->firstOrFail();
    $this->administrator = User::query()
        ->where('email', 'admin@c89.com.mx')
        ->firstOrFail();
    $this->collaborator = User::query()
        ->where('email', 'colaborador@c89.com.mx')
        ->firstOrFail();
    $this->course = Course::factory()->for($this->team)->published()->create();
    $this->courseModule = CourseModule::factory()
        ->for($this->course)
        ->published()
        ->create();
});

it('stores an uploaded PDF as a private document lesson', function () {
    $document = UploadedFile::fake()->create(
        'manual-seguridad.pdf',
        100,
        'application/pdf',
    );

    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.modules.lessons', [
            'course' => $this->course->slug,
            'courseModule' => $this->courseModule->id,
        ])
        ->set('lessonTitle', 'Manual de seguridad')
        ->set('lessonType', LessonType::Document->value)
        ->set('lessonDocument', $document)
        ->set('lessonEstimatedDurationMinutes', 20)
        ->call('createLesson')
        ->assertHasNoErrors()
        ->assertSee('Manual de seguridad');

    $lesson = Lesson::query()->where('title', 'Manual de seguridad')->firstOrFail();

    expect($lesson->type)->toBe(LessonType::Document)
        ->and($lesson->resource_disk)->toBe('local')
        ->and($lesson->resource_name)->toBe('manual-seguridad.pdf')
        ->and($lesson->resource_mime)->toBe('application/pdf')
        ->and($lesson->resource_size)->toBeGreaterThan(0)
        ->and($lesson->content)->toBeNull()
        ->and($lesson->external_url)->toBeNull();

    Storage::disk('local')->assertExists((string) $lesson->resource_path);
    Storage::disk('public')->assertMissing((string) $lesson->resource_path);
});

it('rejects files that are not PDFs', function () {
    $invalidDocument = UploadedFile::fake()->create(
        'programa.exe',
        100,
        'application/x-msdownload',
    );

    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.modules.lessons', [
            'course' => $this->course->slug,
            'courseModule' => $this->courseModule->id,
        ])
        ->set('lessonTitle', 'Archivo no permitido')
        ->set('lessonType', LessonType::Document->value)
        ->set('lessonDocument', $invalidDocument)
        ->call('createLesson')
        ->assertHasErrors(['lessonDocument' => 'mimes']);

    expect(Lesson::query()->where('title', 'Archivo no permitido')->exists())->toBeFalse();
});

it('replaces a PDF and removes the previously stored file', function () {
    $oldPath = 'course-resources/old-document.pdf';
    Storage::disk('local')->put($oldPath, 'old content');

    $lesson = Lesson::factory()
        ->for($this->courseModule)
        ->document()
        ->create([
            'resource_path' => $oldPath,
            'resource_name' => 'old-document.pdf',
        ]);
    $newDocument = UploadedFile::fake()->create(
        'new-document.pdf',
        120,
        'application/pdf',
    );

    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.modules.lessons', [
            'course' => $this->course->slug,
            'courseModule' => $this->courseModule->id,
        ])
        ->call('startEditingLesson', $lesson->id)
        ->set('editingLessonDocument', $newDocument)
        ->call('updateLesson')
        ->assertHasNoErrors();

    $lesson->refresh();

    expect($lesson->resource_name)->toBe('new-document.pdf')
        ->and($lesson->resource_path)->not->toBe($oldPath);

    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists((string) $lesson->resource_path);
});

it('allows an administrator to download a private PDF', function () {
    $lesson = createStoredDocumentLesson($this->courseModule);

    $this->actingAs($this->administrator)
        ->get(documentDownloadRoute($this->team, $this->course, $this->courseModule, $lesson))
        ->assertOk()
        ->assertDownload('manual.pdf');
});

it('requires an enrollment before a collaborator can download a private PDF', function () {
    $lesson = createStoredDocumentLesson($this->courseModule);
    $route = documentDownloadRoute($this->team, $this->course, $this->courseModule, $lesson);

    $this->actingAs($this->collaborator)
        ->get($route)
        ->assertForbidden();

    CourseEnrollment::factory()
        ->for($this->course)
        ->for($this->collaborator)
        ->create();

    $this->actingAs($this->collaborator)
        ->get($route)
        ->assertOk()
        ->assertDownload('manual.pdf');
});

it('returns not found when the private file is missing', function () {
    $lesson = Lesson::factory()
        ->for($this->courseModule)
        ->document()
        ->create([
            'resource_path' => 'course-resources/missing.pdf',
            'resource_name' => 'missing.pdf',
        ]);

    $this->actingAs($this->administrator)
        ->get(documentDownloadRoute($this->team, $this->course, $this->courseModule, $lesson))
        ->assertNotFound();
});

function createStoredDocumentLesson(CourseModule $courseModule): Lesson
{
    $path = 'course-resources/'.$courseModule->id.'/manual.pdf';
    Storage::disk('local')->put($path, '%PDF test content');

    return Lesson::factory()
        ->for($courseModule)
        ->document()
        ->published()
        ->create([
            'resource_path' => $path,
            'resource_name' => 'manual.pdf',
        ]);
}

function documentDownloadRoute(
    Team $team,
    Course $course,
    CourseModule $courseModule,
    Lesson $lesson,
): string {
    return route('lessons.resource', [
        'current_team' => $team,
        'course' => $course,
        'courseModule' => $courseModule,
        'lesson' => $lesson,
    ]);
}
