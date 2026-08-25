<?php

namespace App\Http\Controllers;

use App\Enums\CourseAccessMode;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamAttemptStatus;
use App\Enums\ExamStatus;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationSeatType;
use App\Enums\RoadmapAssignmentStatus;
use App\Enums\TeamRole;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Display the learning dashboard for the current organization.
     */
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        if ($user->isPlatformOwner()) {
            return redirect()->route('platform.dashboard');
        }

        abort_unless($user->currentTeam !== null, 403);

        Gate::authorize('viewAny', Course::class);

        $team = $user->currentTeam;
        $role = $user->teamRole($team);

        if (! $team->is_personal && $role?->isAtLeast(TeamRole::Admin)) {
            return view('admin.dashboard', [
                'activeMembersCount' => $team->memberships()
                    ->where('status', MembershipStatus::Active)
                    ->count(),
                'pendingInvitationsCount' => $team->invitations()
                    ->whereNull('accepted_at')
                    ->where(fn ($query) => $query
                        ->whereNull('expires_at')
                        ->orWhere('expires_at', '>=', now()))
                    ->count(),
                'publishedCoursesCount' => $team->courses()
                    ->where('status', CourseStatus::Published)
                    ->count(),
                'draftCoursesCount' => $team->courses()
                    ->where('status', CourseStatus::Draft)
                    ->count(),
                'publishedExamsCount' => $team->exams()
                    ->where('status', ExamStatus::Published)
                    ->count(),
                'draftExamsCount' => $team->exams()
                    ->where('status', ExamStatus::Draft)
                    ->count(),
                'pendingExamReviewsCount' => $team->exams()
                    ->whereHas('attempts', fn ($query) => $query->where('status', ExamAttemptStatus::PendingReview))
                    ->withCount(['attempts as pending_reviews_count' => fn ($query) => $query->where('status', ExamAttemptStatus::PendingReview)])
                    ->get()
                    ->sum('pending_reviews_count'),
                'administratorSeats' => [
                    'occupied' => $team->occupiedSeats(OrganizationSeatType::Administrator),
                    'capacity' => $team->seatCapacity(OrganizationSeatType::Administrator),
                ],
                'collaboratorSeats' => [
                    'occupied' => $team->occupiedSeats(OrganizationSeatType::Collaborator),
                    'capacity' => $team->seatCapacity(OrganizationSeatType::Collaborator),
                ],
                'recentCourses' => $team->courses()->latest()->limit(4)->get(),
                'recentExams' => $team->exams()->withCount('questions')->latest()->limit(4)->get(),
                'twoFactorEnabled' => $user->two_factor_confirmed_at !== null,
            ]);
        }

        $canSelfEnroll = $user->teamRole($team) === TeamRole::Member;
        $availableCourses = $team
            ->courses()
            ->where('status', CourseStatus::Published)
            ->when($canSelfEnroll, fn ($query) => $query
                ->where('access_mode', '!=', CourseAccessMode::Blocked)
                ->where(fn ($accessQuery) => $accessQuery
                    ->where('access_mode', CourseAccessMode::Open)
                    ->orWhereHas('enrollments', fn ($enrollmentQuery) => $enrollmentQuery
                        ->where('user_id', $user->id)
                        ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed]))))
            ->withCount([
                'lessons as published_lessons_count' => fn ($query) => $query
                    ->where('lessons.is_published', true)
                    ->where('course_modules.is_published', true),
            ])
            ->latest('published_at')
            ->get();

        $enrollments = $canSelfEnroll
            ? $user->courseEnrollments()
                ->whereHas('course', fn ($query) => $query->where('team_id', $team->id))
                ->withCount([
                    'lessonProgress as completed_published_lessons_count' => fn ($query) => $query
                        ->whereNotNull('completed_at')
                        ->whereHas('lesson', fn ($lessonQuery) => $lessonQuery
                            ->where('is_published', true)
                            ->whereHas('courseModule', fn ($moduleQuery) => $moduleQuery
                                ->where('is_published', true))),
                ])
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
            'roadmapAssignments' => $user->roadmapAssignments()
                ->where('status', RoadmapAssignmentStatus::Active)
                ->whereHas('roadmap', fn ($query) => $query->where('team_id', $team->id))
                ->with(['roadmap' => fn ($query) => $query->withCount('items')])
                ->latest('assigned_at')
                ->get(),
        ]);
    }
}
