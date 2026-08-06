<?php

namespace App\Enums;

enum MembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activa',
            self::Suspended => 'Suspendida',
            self::Removed => 'Removida',
        };
    }

    public function consumesSeat(): bool
    {
        return $this !== self::Removed;
    }

    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }
}
