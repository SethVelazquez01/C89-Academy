<?php

namespace App\Models;

use App\Enums\ExamSecurityLevel;
use App\Enums\ExamStatus;
use Database\Factories\ExamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string|null $instructions
 * @property ExamStatus $status
 * @property ExamSecurityLevel $security_level
 * @property int $time_limit_minutes
 * @property int $passing_score
 * @property int $max_attempts
 * @property bool $randomize_questions
 * @property bool $randomize_options
 * @property bool $allow_backtracking
 * @property bool $fullscreen_required
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team $team
 * @property-read User|null $creator
 * @property-read Collection<int, ExamQuestion> $questions
 * @property-read Collection<int, ExamAttempt> $attempts
 */
#[Fillable([
    'team_id',
    'created_by',
    'title',
    'slug',
    'summary',
    'instructions',
    'status',
    'security_level',
    'time_limit_minutes',
    'passing_score',
    'max_attempts',
    'randomize_questions',
    'randomize_options',
    'allow_backtracking',
    'fullscreen_required',
    'published_at',
])]
class Exam extends Model
{
    /** @use HasFactory<ExamFactory> */
    use HasFactory, SoftDeletes;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Exam $exam) {
            if (blank($exam->slug)) {
                $exam->slug = $exam->generateUniqueSlug();
            }
        });
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ExamQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class)->orderBy('position');
    }

    /** @return HasMany<ExamAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    /** @return BelongsToMany<Course, $this> */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class)->withTimestamps();
    }

    /** @return HasMany<RoadmapItem, $this> */
    public function roadmapItems(): HasMany
    {
        return $this->hasMany(RoadmapItem::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    private function generateUniqueSlug(): string
    {
        $baseSlug = Str::slug($this->title) ?: 'examen';
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ExamStatus::class,
            'security_level' => ExamSecurityLevel::class,
            'time_limit_minutes' => 'integer',
            'passing_score' => 'integer',
            'max_attempts' => 'integer',
            'randomize_questions' => 'boolean',
            'randomize_options' => 'boolean',
            'allow_backtracking' => 'boolean',
            'fullscreen_required' => 'boolean',
            'published_at' => 'datetime',
        ];
    }
}
