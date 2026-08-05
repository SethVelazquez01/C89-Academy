<?php

namespace App\Http\Controllers;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\TeamRole;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Display the learning dashboard for the current organization.
     */
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->currentTeam !== null, 403);

        Gate::authorize('viewAny', Course::class);

        $team = $user->currentTeam;
        $availableCourses = $team
            ->courses()
            ->where('status', CourseStatus::Published)
            ->latest('published_at')
            ->get();

        $canSelfEnroll = $user->teamRole($team) === TeamRole::Member;
        $enrollments = $canSelfEnroll
            ? $user->courseEnrollments()
                ->whereHas('course', fn ($query) => $query->where('team_id', $team->id))
                ->get()
            : collect();
        $enrollmentIds = $enrollments->pluck('id');
        $enrolledCourseIds = $enrollments->pluck('course_id');
        $totalPublishedLessons = Lesson::query()
            ->where('is_published', true)
            ->whereHas('courseModule', fn ($query) => $query
                ->whereIn('course_id', $enrolledCourseIds)
                ->where('is_published', true))
            ->count();
        $completedLessons = LessonProgress::query()
            ->whereIn('course_enrollment_id', $enrollmentIds)
            ->whereNotNull('completed_at')
            ->whereHas('lesson', fn ($query) => $query
                ->where('is_published', true)
                ->whereHas('courseModule', fn ($moduleQuery) => $moduleQuery
                    ->whereIn('course_id', $enrolledCourseIds)
                    ->where('is_published', true)))
            ->count();
        $overallProgressPercentage = $totalPublishedLessons > 0
            ? (int) round(($completedLessons / $totalPublishedLessons) * 100)
            : 0;

        return view('dashboard', [
            'availableCourses' => $availableCourses,
            'canSelfEnroll' => $canSelfEnroll,
            'enrollmentsByCourse' => $enrollments->keyBy('course_id'),
            'activeEnrollmentsCount' => $enrollments
                ->where('status', EnrollmentStatus::Active)
                ->count(),
            'completedEnrollmentsCount' => $enrollments
                ->where('status', EnrollmentStatus::Completed)
                ->count(),
            'overallProgressPercentage' => $overallProgressPercentage,
        ]);
    }
}
