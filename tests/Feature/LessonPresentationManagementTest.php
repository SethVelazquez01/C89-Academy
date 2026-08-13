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

    $this->seed(C89OrganizationSeeder::class);

    $this->team = Team::query()->where('slug', 'c89')->firstOrFail();
    $this->administrator = User::query()->where('email', 'admin@c89.com.mx')->firstOrFail();
    $this->collaborator = User::query()->where('email', 'colaborador@c89.com.mx')->firstOrFail();
    $this->course = Course::factory()->for($this->team)->published()->create();
    $this->courseModule = CourseModule::factory()->for($this->course)->published()->create();
});

it('stores a valid PPTX as a private presentation lesson', function () {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.modules.lessons', [
            'course' => $this->course->slug,
            'courseModule' => $this->courseModule->id,
        ])
        ->set('lessonTitle', 'Presentación de seguridad')
        ->set('lessonType', LessonType::Presentation->value)
        ->set('lessonDocument', validPresentationUpload())
        ->set('lessonEstimatedDurationMinutes', 12)
        ->call('createLesson')
        ->assertHasNoErrors();

    $lesson = Lesson::query()->where('title', 'Presentación de seguridad')->firstOrFail();

    expect($lesson->type)->toBe(LessonType::Presentation)
        ->and($lesson->resource_disk)->toBe('local')
        ->and($lesson->resource_name)->toBe('seguridad.pptx')
        ->and($lesson->resource_mime)->toBe('application/vnd.openxmlformats-officedocument.presentationml.presentation')
        ->and($lesson->resource_path)->toEndWith('.pptx');

    Storage::disk('local')->assertExists((string) $lesson->resource_path);
});

it('rejects legacy or damaged PowerPoint files', function (UploadedFile $presentation) {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.modules.lessons', [
            'course' => $this->course->slug,
            'courseModule' => $this->courseModule->id,
        ])
        ->set('lessonTitle', 'Presentación insegura')
        ->set('lessonType', LessonType::Presentation->value)
        ->set('lessonDocument', $presentation)
        ->call('createLesson')
        ->assertHasErrors(['lessonDocument']);

    expect(Lesson::query()->where('title', 'Presentación insegura')->exists())->toBeFalse();
})->with([
    'formato PPT heredado' => fn () => validPresentationUpload('seguridad.ppt'),
    'archivo PPTX dañado' => fn () => UploadedFile::fake()->createWithContent('dañado.pptx', 'not-a-zip'),
    'archivo con macros renombrado' => fn () => macroEnabledPresentationUpload(),
]);

it('serves a private PPTX inline only to authorized users', function () {
    $lesson = storedPresentationLesson($this->courseModule);
    $route = presentationResourceRoute($this->team, $this->course, $this->courseModule, $lesson);

    $this->actingAs($this->collaborator)->get($route)->assertForbidden();

    CourseEnrollment::factory()->for($this->course)->for($this->collaborator)->create();

    $this->actingAs($this->collaborator)
        ->get($route)
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.presentationml.presentation')
        ->assertHeader('content-disposition', 'inline; filename=seguridad.pptx')
        ->assertHeader('cache-control', 'max-age=0, no-store, private');
});

it('renders the private presentation viewer in the course player', function () {
    $lesson = storedPresentationLesson($this->courseModule);
    CourseEnrollment::factory()->for($this->course)->for($this->collaborator)->create();

    $this->actingAs($this->collaborator)
        ->get(route('courses.show', [
            'current_team' => $this->team,
            'course' => $this->course,
        ]))
        ->assertOk()
        ->assertSee($lesson->resource_name)
        ->assertSee('Preparando la presentación')
        ->assertSee('x-ref="presentationContainer"', false);
});

function validPresentationUpload(string $name = 'seguridad.pptx'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'c89-pptx-');

    if (! is_string($path)) {
        throw new RuntimeException('No fue posible crear el archivo temporal.');
    }

    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $archive->addFromString(
        '[Content_Types].xml',
        '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/></Types>',
    );
    $archive->addFromString('ppt/presentation.xml', '<?xml version="1.0"?><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"></p:presentation>');
    $archive->close();

    $contents = file_get_contents($path);
    unlink($path);

    if (! is_string($contents)) {
        throw new RuntimeException('No fue posible leer el archivo temporal.');
    }

    return UploadedFile::fake()->createWithContent($name, $contents);
}

function macroEnabledPresentationUpload(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'c89-pptm-');

    if (! is_string($path)) {
        throw new RuntimeException('No fue posible crear el archivo temporal.');
    }

    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $archive->addFromString(
        '[Content_Types].xml',
        '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/ppt/presentation.xml" ContentType="application/vnd.ms-powerpoint.presentation.macroEnabled.main+xml"/></Types>',
    );
    $archive->addFromString('ppt/presentation.xml', '<p:presentation/>');
    $archive->addFromString('ppt/vbaProject.bin', 'macro');
    $archive->close();

    $contents = file_get_contents($path);
    unlink($path);

    if (! is_string($contents)) {
        throw new RuntimeException('No fue posible leer el archivo temporal.');
    }

    return UploadedFile::fake()->createWithContent('macros-renombradas.pptx', $contents);
}

function storedPresentationLesson(CourseModule $courseModule): Lesson
{
    $path = 'course-resources/'.$courseModule->id.'/seguridad.pptx';
    Storage::disk('local')->put($path, 'private presentation');

    return Lesson::factory()
        ->for($courseModule)
        ->presentation()
        ->published()
        ->create([
            'resource_path' => $path,
            'resource_name' => 'seguridad.pptx',
        ]);
}

function presentationResourceRoute(
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
