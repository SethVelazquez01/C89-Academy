<?php

namespace App\Enums;

enum ExamAttemptEventType: string
{
    case TabHidden = 'tab_hidden';
    case WindowBlurred = 'window_blurred';
    case FullscreenExited = 'fullscreen_exited';
    case CopyAttempted = 'copy_attempted';
    case ContextMenuAttempted = 'context_menu_attempted';

    public function label(): string
    {
        return match ($this) {
            self::TabHidden => 'Salió de la pestaña',
            self::WindowBlurred => 'La ventana perdió el foco',
            self::FullscreenExited => 'Salió de pantalla completa',
            self::CopyAttempted => 'Intentó copiar contenido',
            self::ContextMenuAttempted => 'Intentó abrir el menú contextual',
        };
    }
}
