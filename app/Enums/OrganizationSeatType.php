<?php

namespace App\Enums;

enum OrganizationSeatType: string
{
    case Administrator = 'administrator';
    case Collaborator = 'collaborator';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrador',
            self::Collaborator => 'Colaborador',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Administrator => 'Administradores',
            self::Collaborator => 'Colaboradores',
        };
    }

    /**
     * Get the organization roles that consume this seat type.
     *
     * @return array<TeamRole>
     */
    public function roles(): array
    {
        return match ($this) {
            self::Administrator => [TeamRole::Owner, TeamRole::Admin],
            self::Collaborator => [TeamRole::Member],
        };
    }

    public static function fromRole(TeamRole $role): self
    {
        return match ($role) {
            TeamRole::Owner, TeamRole::Admin => self::Administrator,
            TeamRole::Member => self::Collaborator,
        };
    }
}
