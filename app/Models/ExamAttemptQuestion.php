<?php

namespace App\Models;

use App\Enums\ExamQuestionType;
use Database\Factories\ExamAttemptQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $exam_attempt_id
 * @property int|null $exam_question_id
 * @property ExamQuestionType $type
 * @property string $prompt
 * @property array<int, string>|null $options
 * @property string|null $correct_answer
 * @property int $points
 * @property int $position
 * @property string|null $answer
 * @property bool|null $is_correct
 * @property int|null $awarded_points
 * @property int|null $reviewed_by
 * @property string|null $review_comment
 * @property Carbon|null $answered_at
 * @property Carbon|null $reviewed_at
 * @property-read ExamAttempt $attempt
 * @property-read ExamQuestion|null $sourceQuestion
 * @property-read User|null $reviewer
 */
#[Fillable([
    'exam_attempt_id', 'exam_question_id', 'type', 'prompt', 'options', 'correct_answer',
    'points', 'position', 'answer', 'is_correct', 'awarded_points', 'reviewed_by',
    'review_comment', 'answered_at', 'reviewed_at',
])]
class ExamAttemptQuestion extends Model
{
    /** @use HasFactory<ExamAttemptQuestionFactory> */
    use HasFactory;

    /** @return BelongsTo<ExamAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }

    /** @return BelongsTo<ExamQuestion, $this> */
    public function sourceQuestion(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'exam_question_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ExamQuestionType::class,
            'options' => 'array',
            'is_correct' => 'boolean',
            'answered_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
