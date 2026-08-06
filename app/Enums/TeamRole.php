<?php

namespace App\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /**
     * Get the display label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Propietario',
            self::Admin => 'Administrador',
            self::Member => 'Colaborador',
        };
    }

    /**
     * Get all the permissions for this role.
     *
     * @return array<TeamPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => TeamPermission::cases(),
            self::Admin => [
                TeamPermission::UpdateTeam,
                TeamPermission::CreateInvitation,
                TeamPermission::CancelInvitation,
                TeamPermission::UpdateMember,
                TeamPermission::ManageMemberStatus,
                TeamPermission::RemoveMember,
                TeamPermission::CreateCourse,
                TeamPermission::UpdateCourse,
                TeamPermission::PublishCourse,
                TeamPermission::DeleteCourse,
            ],
            self::Member => [],
        };
    }

    /**
     * Determine if the role has the given permission.
     */
    public function hasPermission(TeamPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Get the hierarchy level for this role.
     */
    public function level(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Admin => 2,
            self::Member => 1,
        };
    }

    /**
     * Check if this role is at least as privileged as another role.
     */
    public function isAtLeast(TeamRole $role): bool
    {
        return $this->level() >= $role->level();
    }

    /**
     * Get roles that may be assigned through organization member management.
     *
     * @return array<array{value: string, label: string}>
     */
    public static function assignable(): array
    {
        return collect(self::assignableCases())
            ->map(fn (self $role) => ['value' => $role->value, 'label' => $role->label()])
            ->values()
            ->toArray();
    }

    /**
     * Get assignable role values for server-side validation.
     *
     * @return array<string>
     */
    public static function assignableValues(): array
    {
        return array_map(
            fn (self $role): string => $role->value,
            self::assignableCases(),
        );
    }

    /**
     * The legacy tenant owner role cannot be granted through member forms.
     *
     * @return array<self>
     */
    private static function assignableCases(): array
    {
        return [self::Admin, self::Member];
    }
}
