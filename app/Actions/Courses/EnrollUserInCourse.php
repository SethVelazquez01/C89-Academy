<?php

namespace App\Actions\Courses;

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class EnrollUserInCourse
{
    /**
     * Enroll the user without creating duplicate enrollment records.
     */
    public function handle(User $user, Course $course): CourseEnrollment
    {
        return DB::transaction(function () use ($user, $course): CourseEnrollment {
            $enrollment = CourseEnrollment::query()
                ->where('course_id', $course->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($enrollment === null) {
                return CourseEnrollment::query()->create([
                    'course_id' => $course->id,
                    'user_id' => $user->id,
                    'assigned_by' => null,
                    'status' => EnrollmentStatus::Active,
                    'enrolled_at' => now(),
                    'completed_at' => null,
                ]);
            }

            if ($enrollment->status === EnrollmentStatus::Cancelled) {
                $enrollment->update([
                    'assigned_by' => null,
                    'status' => EnrollmentStatus::Active,
                    'enrolled_at' => now(),
                    'completed_at' => null,
                ]);

                $enrollment->refresh();
            }

            return $enrollment;
        });
    }
}
