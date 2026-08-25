<?php

namespace App\Models;

use App\Enums\ExamQuestionType;
use Database\Factories\ExamQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $exam_id
 * @property ExamQuestionType $type
 * @property string $prompt
 * @property array<int, string>|null $options
 * @property string|null $correct_answer
 * @property string|null $explanation
 * @property int $points
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Exam $exam
 */
#[Fillable([
    'exam_id',
    'type',
    'prompt',
    'options',
    'correct_answer',
    'explanation',
    'points',
    'position',
])]
class ExamQuestion extends Model
{
    /** @use HasFactory<ExamQuestionFactory> */
    use HasFactory;

    /** @return BelongsTo<Exam, $this> */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ExamQuestionType::class,
            'options' => 'array',
            'points' => 'integer',
            'position' => 'integer',
        ];
    }
}
