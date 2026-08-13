<?php

namespace App\Models;

use App\Enums\EnrollmentProgressStatus;
use App\Enums\EnrollmentStatus;
use Database\Factories\CourseEnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $course_id
 * @property int $user_id
 * @property int|null $assigned_by
 * @property bool $is_required
 * @property Carbon|null $due_at
 * @property int|null $last_cancelled_by
 * @property Carbon|null $last_cancelled_at
 * @property EnrollmentStatus $status
 * @property Carbon $enrolled_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Course $course
 * @property-read User $user
 * @property-read User|null $assigner
 * @property-read User|null $lastCanceller
 * @property-read Collection<int, LessonProgress> $lessonProgress
 * @property-read int|null $completed_published_lessons_count
 */
#[Fillable([
    'course_id',
    'user_id',
    'assigned_by',
    'is_required',
    'due_at',
    'last_cancelled_by',
    'last_cancelled_at',
    'status',
    'enrolled_at',
    'completed_at',
])]
class CourseEnrollment extends Model
{
    /** @use HasFactory<CourseEnrollmentFactory> */
    use HasFactory;

    /**
     * Get the enrolled course.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the enrolled user.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the administrator who assigned the course, when applicable.
     *
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Get the administrator who most recently cancelled the enrollment.
     *
     * @return BelongsTo<User, $this>
     */
    public function lastCanceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_cancelled_by');
    }

    /**
     * Get progress for lessons in this enrollment.
     *
     * @return HasMany<LessonProgress, $this>
     */
    public function lessonProgress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function progressStatus(int $completedLessons, int $totalLessons): EnrollmentProgressStatus
    {
        if ($this->status === EnrollmentStatus::Cancelled) {
            return EnrollmentProgressStatus::Cancelled;
        }

        if ($this->status === EnrollmentStatus::Completed
            || ($totalLessons > 0 && $completedLessons >= $totalLessons)) {
            return EnrollmentProgressStatus::Completed;
        }

        if ($this->due_at?->isPast()) {
            return EnrollmentProgressStatus::Overdue;
        }

        return $completedLessons > 0
            ? EnrollmentProgressStatus::InProgress
            : EnrollmentProgressStatus::Pending;
    }

    public function progressPercentage(int $completedLessons, int $totalLessons): int
    {
        if ($this->status === EnrollmentStatus::Completed) {
            return 100;
        }

        if ($totalLessons <= 0) {
            return 0;
        }

        return (int) round(min($completedLessons / $totalLessons, 1) * 100);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'due_at' => 'datetime',
            'last_cancelled_at' => 'datetime',
            'status' => EnrollmentStatus::class,
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
