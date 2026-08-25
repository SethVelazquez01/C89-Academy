<?php

namespace App\Models;

use App\Enums\ExamAttemptEventType;
use Database\Factories\ExamAttemptEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $exam_attempt_id
 * @property ExamAttemptEventType $type
 * @property array<string, mixed>|null $metadata
 * @property Carbon $occurred_at
 * @property-read ExamAttempt $attempt
 */
#[Fillable(['exam_attempt_id', 'type', 'metadata', 'occurred_at'])]
class ExamAttemptEvent extends Model
{
    /** @use HasFactory<ExamAttemptEventFactory> */
    use HasFactory;

    /** @return BelongsTo<ExamAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ExamAttemptEventType::class,
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
