<?php

namespace App\Http\Controllers;

use App\Enums\LessonType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LessonResourceController extends Controller
{
    /**
     * Download a private lesson document after authorization.
     */
    public function __invoke(
        Request $request,
        string $_currentTeam,
        string $course,
        int $courseModule,
        string $lesson,
    ): StreamedResponse {
        $user = $request->user();

        abort_unless($user instanceof User && $user->currentTeam !== null, 403);

        $courseModel = $user->currentTeam
            ->courses()
            ->where('slug', $course)
            ->firstOrFail();
        $courseModuleModel = $courseModel
            ->modules()
            ->whereKey($courseModule)
            ->firstOrFail();
        $lessonModel = $courseModuleModel
            ->lessons()
            ->where('slug', $lesson)
            ->firstOrFail();

        Gate::authorize('view', $lessonModel);

        abort_unless(
            $lessonModel->type === LessonType::Document
                && filled($lessonModel->resource_disk)
                && filled($lessonModel->resource_path)
                && filled($lessonModel->resource_name),
            404,
        );

        $disk = (string) $lessonModel->resource_disk;
        $path = (string) $lessonModel->resource_path;

        abort_unless(config("filesystems.disks.{$disk}") !== null, 404);
        abort_unless(Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->download(
            $path,
            (string) $lessonModel->resource_name,
            ['Content-Type' => $lessonModel->resource_mime ?? 'application/pdf'],
        );
    }
}
