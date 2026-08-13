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

it('stores one uploaded image as a private image lesson', function () {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.modules.lessons', [
            'course' => $this->course->slug,
            'courseModule' => $this->courseModule->id,
        ])
        ->set('lessonTitle', 'Diagrama de seguridad')
        ->set('lessonType', LessonType::Image->value)
        ->set('lessonDocument', UploadedFile::fake()->image('diagrama.jpg', 1600, 900))
        ->set('lessonContent', 'Este diagrama explica el flujo seguro de operación.')
        ->call('createLesson')
        ->assertHasNoErrors();

    $lesson = Lesson::query()->where('title', 'Diagrama de seguridad')->firstOrFail();

    expect($lesson->type)->toBe(LessonType::Image)
        ->and($lesson->resource_disk)->toBe('local')
        ->and($lesson->resource_name)->toBe('diagrama.jpg')
        ->and($lesson->resource_mime)->toBe('image/jpeg')
        ->and($lesson->resource_path)->toEndWith('.jpg')
        ->and($lesson->content)->toBe('Este diagrama explica el flujo seguro de operación.')
        ->and($lesson->external_url)->toBeNull();

    Storage::disk('local')->assertExists((string) $lesson->resource_path);
});

it('rejects unsafe or unsupported image files', function (UploadedFile $image) {
    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.modules.lessons', [
            'course' => $this->course->slug,
            'courseModule' => $this->courseModule->id,
        ])
        ->set('lessonTitle', 'Imagen no permitida')
        ->set('lessonType', LessonType::Image->value)
        ->set('lessonDocument', $image)
        ->call('createLesson')
        ->assertHasErrors(['lessonDocument']);

    expect(Lesson::query()->where('title', 'Imagen no permitida')->exists())->toBeFalse();
})->with([
    'SVG con código activo' => fn () => UploadedFile::fake()->createWithContent(
        'activa.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    ),
    'archivo ejecutable' => fn () => UploadedFile::fake()->create(
        'programa.exe',
        100,
        'application/x-msdownload',
    ),
]);

it('replaces the only lesson image and removes the previous file', function () {
    $oldPath = 'course-resources/old-image.jpg';
    Storage::disk('local')->put($oldPath, 'old image');
    $lesson = Lesson::factory()
        ->for($this->courseModule)
        ->image()
        ->create([
            'resource_path' => $oldPath,
            'resource_name' => 'old-image.jpg',
            'resource_mime' => 'image/jpeg',
        ]);

    Livewire::actingAs($this->administrator)
        ->test('pages::admin.courses.modules.lessons', [
            'course' => $this->course->slug,
            'courseModule' => $this->courseModule->id,
        ])
        ->call('startEditingLesson', $lesson->id)
        ->set('editingLessonDocument', UploadedFile::fake()->image('nueva-imagen.png', 1600, 900))
        ->set('editingLessonContent', 'Descripción actualizada sin mostrar el nombre del archivo.')
        ->call('updateLesson')
        ->assertHasNoErrors();

    $lesson->refresh();

    expect($lesson->resource_name)->toBe('nueva-imagen.png')
        ->and($lesson->resource_mime)->toBe('image/png')
        ->and($lesson->content)->toBe('Descripción actualizada sin mostrar el nombre del archivo.')
        ->and($lesson->resource_path)->not->toBe($oldPath);

    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists((string) $lesson->resource_path);
});

it('serves the private image inline only to authorized users', function () {
    $lesson = storedImageLesson($this->courseModule);
    $route = imageResourceRoute($this->team, $this->course, $this->courseModule, $lesson);

    $this->actingAs($this->collaborator)->get($route)->assertForbidden();

    CourseEnrollment::factory()->for($this->course)->for($this->collaborator)->create();

    $this->actingAs($this->collaborator)
        ->get($route)
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg')
        ->assertHeader('content-disposition', 'inline; filename=diagrama.jpg')
        ->assertHeader('cache-control', 'max-age=0, no-store, private');
});

it('shows the large private image inside the course player', function () {
    $lesson = storedImageLesson($this->courseModule);
    CourseEnrollment::factory()->for($this->course)->for($this->collaborator)->create();

    $this->actingAs($this->collaborator)
        ->get(route('courses.show', [
            'current_team' => $this->team,
            'course' => $this->course,
        ]))
        ->assertOk()
        ->assertSee('Descripción visible de la imagen de seguridad.')
        ->assertDontSee($lesson->resource_name)
        ->assertSee('max-h-[75vh]', false)
        ->assertSee(imageResourceRoute($this->team, $this->course, $this->courseModule, $lesson), false);
});

function storedImageLesson(CourseModule $courseModule): Lesson
{
    $path = 'course-resources/'.$courseModule->id.'/diagrama.jpg';
    Storage::disk('local')->put($path, 'private image');

    return Lesson::factory()
        ->for($courseModule)
        ->image()
        ->published()
        ->create([
            'resource_path' => $path,
            'resource_name' => 'diagrama.jpg',
            'resource_mime' => 'image/jpeg',
            'content' => 'Descripción visible de la imagen de seguridad.',
        ]);
}

function imageResourceRoute(
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
