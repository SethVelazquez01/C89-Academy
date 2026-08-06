<?php

namespace App\Enums;

enum PlatformRole: string
{
    case Owner = 'owner';

    /**
     * Get the display label for the global platform role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Propietario de plataforma',
        };
    }
}
