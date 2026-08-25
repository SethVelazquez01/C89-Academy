<?php

namespace App\Models;

use App\Enums\RoadmapAssignmentStatus;
use Database\Factories\RoadmapAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $roadmap_id
 * @property int $user_id
 * @property int|null $assigned_by
 * @property int|null $cancelled_by
 * @property RoadmapAssignmentStatus $status
 * @property Carbon $assigned_at
 * @property Carbon|null $due_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Roadmap $roadmap
 * @property-read User $user
 * @property-read User|null $assigner
 * @property-read User|null $canceller
 */
#[Fillable(['roadmap_id', 'user_id', 'assigned_by', 'cancelled_by', 'status', 'assigned_at', 'due_at', 'cancelled_at'])]
class RoadmapAssignment extends Model
{
    /** @use HasFactory<RoadmapAssignmentFactory> */
    use HasFactory;

    /** @return BelongsTo<Roadmap, $this> */
    public function roadmap(): BelongsTo
    {
        return $this->belongsTo(Roadmap::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RoadmapAssignmentStatus::class,
            'assigned_at' => 'datetime',
            'due_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
