<?php

namespace App\Enums;

enum EnrollmentProgressStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::InProgress => 'En curso',
            self::Completed => 'Completado',
            self::Overdue => 'Atrasado',
            self::Cancelled => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'zinc',
            self::InProgress => 'blue',
            self::Completed => 'green',
            self::Overdue => 'red',
            self::Cancelled => 'amber',
        };
    }
}
