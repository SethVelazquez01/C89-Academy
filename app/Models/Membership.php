<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property TeamRole $role
 * @property MembershipStatus $status
 * @property int|null $created_by
 * @property int|null $status_changed_by
 * @property Carbon|null $status_changed_at
 * @property int|null $role_changed_by
 * @property Carbon|null $role_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User $user
 * @property-read User|null $creator
 * @property-read User|null $statusChanger
 * @property-read User|null $roleChanger
 */
#[Fillable(['team_id', 'user_id', 'role', 'status', 'created_by', 'status_changed_by', 'status_changed_at', 'role_changed_by', 'role_changed_at'])]
class Membership extends Pivot
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'team_members';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Get the team that the membership belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user that belongs to this membership.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the identity that created the membership.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the identity that last changed the membership status.
     *
     * @return BelongsTo<User, $this>
     */
    public function statusChanger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    /**
     * Get the identity that last changed the membership role.
     *
     * @return BelongsTo<User, $this>
     */
    public function roleChanger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'role_changed_by');
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === MembershipStatus::Suspended;
    }

    public function isRemoved(): bool
    {
        return $this->status === MembershipStatus::Removed;
    }

    public function consumesSeat(): bool
    {
        return $this->status->consumesSeat();
    }

    public function grantsAccess(): bool
    {
        return $this->status->grantsAccess();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
            'status' => MembershipStatus::class,
            'status_changed_at' => 'datetime',
            'role_changed_at' => 'datetime',
        ];
    }
}
