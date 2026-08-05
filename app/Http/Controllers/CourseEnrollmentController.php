<?php

namespace App\Http\Controllers;

use App\Actions\Courses\EnrollUserInCourse;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CourseEnrollmentController extends Controller
{
    /**
     * Enroll the authenticated user in a published course.
     */
    public function __invoke(
        Request $request,
        EnrollUserInCourse $enrollUserInCourse,
        string $_currentTeam,
        string $course,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless($user instanceof User && $user->currentTeam !== null, 403);

        $team = $user->currentTeam;
        $courseModel = $team->courses()
            ->where('slug', $course)
            ->firstOrFail();

        Gate::authorize('create', [CourseEnrollment::class, $courseModel]);

        $enrollUserInCourse->handle($user, $courseModel);

        return redirect()
            ->route('dashboard', ['current_team' => $team])
            ->with('success', 'Tu inscripción se guardó correctamente.');
    }
}
