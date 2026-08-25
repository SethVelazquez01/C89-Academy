<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationSeatType;
use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_personal
 * @property int $included_administrator_seats
 * @property int $additional_administrator_seats
 * @property int $included_collaborator_seats
 * @property int $additional_collaborator_seats
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Course> $courses
 * @property-read Collection<int, Exam> $exams
 * @property-read Collection<int, Roadmap> $roadmaps
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, SeatIncreaseRequest> $seatIncreaseRequests
 * @property-read Collection<int, User> $members
 */
#[Fillable([
    'name',
    'slug',
    'is_personal',
    'included_administrator_seats',
    'additional_administrator_seats',
    'included_collaborator_seats',
    'additional_collaborator_seats',
])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, SoftDeletes;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'included_administrator_seats' => 1,
        'additional_administrator_seats' => 0,
        'included_collaborator_seats' => 1,
        'additional_collaborator_seats' => 0,
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = static::generateUniqueTeamSlug($team->name);
            }
        });

        static::updating(function (Team $team) {
            if ($team->isDirty('name')) {
                $team->slug = static::generateUniqueTeamSlug($team->name, $team->id);
            }
        });
    }

    /**
     * Get the team owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', TeamRole::Owner->value)
            ->first();
    }

    /**
     * Get the courses owned by this organization.
     *
     * @return HasMany<Course, $this>
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /** @return HasMany<Exam, $this> */
    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }

    /** @return HasMany<Roadmap, $this> */
    public function roadmaps(): HasMany
    {
        return $this->hasMany(Roadmap::class);
    }

    /**
     * Get all members of this team.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot([
                'role',
                'status',
                'created_by',
                'status_changed_by',
                'status_changed_at',
                'role_changed_by',
                'role_changed_at',
            ])
            ->wherePivot('status', '!=', MembershipStatus::Removed->value)
            ->withTimestamps();
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /** @return HasMany<SeatIncreaseRequest, $this> */
    public function seatIncreaseRequests(): HasMany
    {
        return $this->hasMany(SeatIncreaseRequest::class);
    }

    public function seatCapacity(OrganizationSeatType $seatType): int
    {
        return match ($seatType) {
            OrganizationSeatType::Administrator => $this->included_administrator_seats
                + $this->additional_administrator_seats,
            OrganizationSeatType::Collaborator => $this->included_collaborator_seats
                + $this->additional_collaborator_seats,
        };
    }

    public function occupiedSeats(OrganizationSeatType $seatType): int
    {
        $roles = array_map(
            fn (TeamRole $role): string => $role->value,
            $seatType->roles(),
        );

        $memberships = $this->memberships()
            ->whereIn('role', $roles)
            ->whereIn('status', [
                MembershipStatus::Active->value,
                MembershipStatus::Suspended->value,
            ])
            ->count();

        $pendingInvitations = $this->invitations()
            ->whereIn('role', $roles)
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->count();

        return $memberships + $pendingInvitations;
    }

    public function availableSeats(OrganizationSeatType $seatType): int
    {
        return max($this->seatCapacity($seatType) - $this->occupiedSeats($seatType), 0);
    }

    public function hasAvailableSeat(OrganizationSeatType $seatType): bool
    {
        return $this->occupiedSeats($seatType) < $this->seatCapacity($seatType);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'included_administrator_seats' => 'integer',
            'additional_administrator_seats' => 'integer',
            'included_collaborator_seats' => 'integer',
            'additional_collaborator_seats' => 'integer',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
