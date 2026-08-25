<?php

namespace App\Enums;

enum ExamSecurityLevel: string
{
    case Standard = 'standard';
    case Controlled = 'controlled';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Estándar',
            self::Controlled => 'Supervisado en navegador',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Standard => 'Aleatorización, límite de tiempo y sesión única.',
            self::Controlled => 'Añade pantalla completa y registro de cambios de pestaña o foco.',
        };
    }
}
