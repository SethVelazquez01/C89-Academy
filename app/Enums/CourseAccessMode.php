<?php

namespace App\Enums;

enum CourseAccessMode: string
{
    case Open = 'open';
    case Assigned = 'assigned';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Libre',
            self::Assigned => 'Sólo con asignación',
            self::Blocked => 'Bloqueado',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Open => 'Todos los colaboradores pueden verlo e inscribirse.',
            self::Assigned => 'Sólo aparece a quienes recibieron permiso o un roadmap.',
            self::Blocked => 'No aparece ni puede abrirse por colaboradores.',
        };
    }
}
