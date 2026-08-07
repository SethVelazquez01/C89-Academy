<?php

namespace App\Models;

use App\Enums\SeatIncreaseRequestStatus;
use Database\Factories\SeatIncreaseRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $requested_by
 * @property int $requested_administrator_seats
 * @property int $requested_collaborator_seats
 * @property string $justification
 * @property SeatIncreaseRequestStatus $status
 * @property bool|null $pending_marker
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User|null $requester
 * @property-read User|null $reviewer
 */
class SeatIncreaseRequest extends Model
{
    /** @use HasFactory<SeatIncreaseRequestFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'team_id',
        'requested_by',
        'requested_administrator_seats',
        'requested_collaborator_seats',
        'justification',
        'status',
        'pending_marker',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
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
            'requested_administrator_seats' => 'integer',
            'requested_collaborator_seats' => 'integer',
            'status' => SeatIncreaseRequestStatus::class,
            'pending_marker' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }
}
