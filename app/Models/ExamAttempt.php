<?php

namespace App\Models;

use App\Enums\ExamAttemptStatus;
use Database\Factories\ExamAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $exam_id
 * @property int $user_id
 * @property int $attempt_number
 * @property ExamAttemptStatus $status
 * @property int $max_points
 * @property int|null $earned_points
 * @property float|null $percentage_score
 * @property bool $timed_out
 * @property int $security_event_count
 * @property Carbon $started_at
 * @property Carbon $expires_at
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $graded_at
 * @property-read Exam $exam
 * @property-read User $user
 * @property-read Collection<int, ExamAttemptQuestion> $questions
 * @property-read Collection<int, ExamAttemptEvent> $events
 */
#[Fillable([
    'uuid', 'exam_id', 'user_id', 'attempt_number', 'status', 'max_points', 'earned_points',
    'percentage_score', 'timed_out', 'security_event_count', 'started_at', 'expires_at',
    'last_activity_at', 'submitted_at', 'graded_at',
])]
class ExamAttempt extends Model
{
    /** @use HasFactory<ExamAttemptFactory> */
    use HasFactory;

    /** @return BelongsTo<Exam, $this> */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ExamAttemptQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(ExamAttemptQuestion::class)->orderBy('position');
    }

    /** @return HasMany<ExamAttemptEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ExamAttemptEvent::class)->latest('occurred_at');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function secondsRemaining(): int
    {
        return max(0, (int) floor(now()->diffInSeconds($this->expires_at, false)));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ExamAttemptStatus::class,
            'percentage_score' => 'float',
            'timed_out' => 'boolean',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
        ];
    }
}
