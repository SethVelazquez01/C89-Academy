<?php

namespace App\Enums;

enum ExamAttemptStatus: string
{
    case InProgress = 'in_progress';
    case PendingReview = 'pending_review';
    case Passed = 'passed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'En curso',
            self::PendingReview => 'Pendiente de revisión',
            self::Passed => 'Aprobado',
            self::Failed => 'No aprobado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::InProgress => 'blue',
            self::PendingReview => 'amber',
            self::Passed => 'green',
            self::Failed => 'red',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Passed, self::Failed], true);
    }
}
