<?php

namespace App\Enums;

enum RoadmapAssignmentStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activa',
            self::Cancelled => 'Cancelada',
        };
    }
}
