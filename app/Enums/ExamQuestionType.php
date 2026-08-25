<?php

namespace App\Enums;

enum ExamQuestionType: string
{
    case SingleChoice = 'single_choice';
    case TrueFalse = 'true_false';
    case ShortAnswer = 'short_answer';

    public function label(): string
    {
        return match ($this) {
            self::SingleChoice => 'Opción múltiple',
            self::TrueFalse => 'Verdadero o falso',
            self::ShortAnswer => 'Abierta',
        };
    }

    public function requiresManualReview(): bool
    {
        return $this === self::ShortAnswer;
    }
}
