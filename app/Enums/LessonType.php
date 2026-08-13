<?php

namespace App\Enums;

enum LessonType: string
{
    case Text = 'text';
    case Video = 'video';
    case Document = 'document';
    case Presentation = 'presentation';
    case Image = 'image';
    case Link = 'link';

    /**
     * Get the display label for the lesson type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Text => 'Texto',
            self::Video => 'Video',
            self::Document => 'Documento',
            self::Presentation => 'Presentación',
            self::Image => 'Imagen',
            self::Link => 'Enlace',
        };
    }
}
