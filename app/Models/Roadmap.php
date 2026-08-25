<?php

namespace App\Models;

use App\Enums\RoadmapStatus;
use Database\Factories\RoadmapFactory;
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
 * @property RoadmapStatus $status
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team $team
 * @property-read User|null $creator
 * @property-read Collection<int, RoadmapItem> $items
 * @property-read Collection<int, RoadmapAssignment> $assignments
 * @property-read Collection<int, Course> $courses
 * @property-read Collection<int, Exam> $exams
 */
#[Fillable(['team_id', 'created_by', 'title', 'slug', 'summary', 'status', 'published_at'])]
class Roadmap extends Model
{
    /** @use HasFactory<RoadmapFactory> */
    use HasFactory, SoftDeletes;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Roadmap $roadmap) {
            if (blank($roadmap->slug)) {
                $roadmap->slug = $roadmap->generateUniqueSlug();
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

    /** @return HasMany<RoadmapItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(RoadmapItem::class)->orderBy('position');
    }

    /** @return HasMany<RoadmapAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(RoadmapAssignment::class);
    }

    /** @return BelongsToMany<Course, $this> */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'roadmap_items')->withPivot('position')->orderByPivot('position');
    }

    /** @return BelongsToMany<Exam, $this> */
    public function exams(): BelongsToMany
    {
        return $this->belongsToMany(Exam::class, 'roadmap_items')->withPivot('position')->orderByPivot('position');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    private function generateUniqueSlug(): string
    {
        $baseSlug = Str::slug($this->title) ?: 'roadmap';
        $slug = $baseSlug;
        $suffix = 2;

        while (static::query()->withTrashed()->where('team_id', $this->team_id)->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => RoadmapStatus::class, 'published_at' => 'datetime'];
    }
}
