<?php

namespace App\Models;

use App\Enums\LessonType;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $course_module_id
 * @property string $title
 * @property string $slug
 * @property LessonType $type
 * @property string|null $content
 * @property string|null $resource_disk
 * @property string|null $resource_path
 * @property string|null $resource_name
 * @property string|null $resource_mime
 * @property int|null $resource_size
 * @property string|null $external_url
 * @property int|null $estimated_duration_minutes
 * @property int $position
 * @property bool $is_published
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read CourseModule $courseModule
 * @property-read Collection<int, LessonProgress> $progressRecords
 */
#[Fillable([
    'course_module_id',
    'title',
    'slug',
    'type',
    'content',
    'resource_disk',
    'resource_path',
    'resource_name',
    'resource_mime',
    'resource_size',
    'external_url',
    'estimated_duration_minutes',
    'position',
    'is_published',
])]
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Lesson $lesson) {
            if (blank($lesson->slug)) {
                $lesson->slug = $lesson->generateUniqueSlug();
            }
        });
    }

    /**
     * Get the module that contains this lesson.
     *
     * @return BelongsTo<CourseModule, $this>
     */
    public function courseModule(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class);
    }

    /**
     * Get the progress records for this lesson.
     *
     * @return HasMany<LessonProgress, $this>
     */
    public function progressRecords(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Generate a slug that is unique within the module.
     */
    private function generateUniqueSlug(): string
    {
        $baseSlug = Str::slug($this->title) ?: 'leccion';
        $slug = $baseSlug;
        $suffix = 2;

        while (static::query()
            ->withTrashed()
            ->where('course_module_id', $this->course_module_id)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LessonType::class,
            'resource_size' => 'integer',
            'estimated_duration_minutes' => 'integer',
            'position' => 'integer',
            'is_published' => 'boolean',
        ];
    }
}
