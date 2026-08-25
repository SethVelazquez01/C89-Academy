<?php

namespace App\Models;

use Database\Factories\RoadmapItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $roadmap_id
 * @property int|null $course_id
 * @property int|null $exam_id
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Roadmap $roadmap
 * @property-read Course|null $course
 * @property-read Exam|null $exam
 */
#[Fillable(['roadmap_id', 'course_id', 'exam_id', 'position'])]
class RoadmapItem extends Model
{
    /** @use HasFactory<RoadmapItemFactory> */
    use HasFactory;

    /** @return BelongsTo<Roadmap, $this> */
    public function roadmap(): BelongsTo
    {
        return $this->belongsTo(Roadmap::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Exam, $this> */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function isCourse(): bool
    {
        return $this->course_id !== null;
    }
}
