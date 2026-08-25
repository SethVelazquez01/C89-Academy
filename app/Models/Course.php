<?php

namespace App\Models;

use App\Enums\CourseAccessMode;
use App\Enums\CourseStatus;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $created_by
 * @property string $title
 * @property string $slug
 * @property string|null $summary
 * @property string|null $description
 * @property CourseStatus $status
 * @property CourseAccessMode $access_mode
 * @property string|null $thumbnail_path
 * @property int|null $estimated_duration_minutes
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team $team
 * @property-read User|null $creator
 * @property-read Collection<int, CourseEnrollment> $enrollments
 * @property-read Collection<int, Lesson> $lessons
 * @property-read Collection<int, CourseModule> $modules
 * @property-read int|null $published_lessons_count
 */
#[Fillable([
    'team_id',
    'created_by',
    'title',
    'slug',
    'summary',
    'description',
    'status',
    'access_mode',
    'thumbnail_path',
    'estimated_duration_minutes',
    'published_at',
])]
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Course $course) {
            if (blank($course->slug)) {
                $course->slug = $course->generateUniqueSlug();
            }
        });
    }

    /**
     * Get the organization that owns the course.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who created the course.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the enrollments for the course.
     *
     * @return HasMany<CourseEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    /**
     * Get the modules ordered as they appear in the course.
     *
     * @return HasMany<CourseModule, $this>
     */
    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class)->orderBy('position');
    }

    /**
     * Get all lessons contained in the course.
     *
     * @return HasManyThrough<Lesson, CourseModule, $this>
     */
    public function lessons(): HasManyThrough
    {
        return $this->hasManyThrough(Lesson::class, CourseModule::class);
    }

    /** @return BelongsToMany<Exam, $this> */
    public function exams(): BelongsToMany
    {
        return $this->belongsToMany(Exam::class)->withTimestamps();
    }

    /** @return HasMany<RoadmapItem, $this> */
    public function roadmapItems(): HasMany
    {
        return $this->hasMany(RoadmapItem::class);
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Generate a slug that is unique within the organization.
     */
    private function generateUniqueSlug(): string
    {
        $baseSlug = Str::slug($this->title) ?: 'curso';
        $slug = $baseSlug;
        $suffix = 2;

        while (static::query()
            ->withTrashed()
            ->where('team_id', $this->team_id)
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
            'status' => CourseStatus::class,
            'access_mode' => CourseAccessMode::class,
            'estimated_duration_minutes' => 'integer',
            'published_at' => 'datetime',
        ];
    }
}
