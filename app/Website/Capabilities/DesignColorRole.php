<?php

namespace App\Website\Capabilities;

enum DesignColorRole: string
{
    case Canvas = 'canvas';
    case Surface = 'surface';
    case Text = 'text';
    case TextMuted = 'textMuted';
    case Accent = 'accent';
    case AccentContrast = 'accentContrast';
    case Border = 'border';
    case Ornament = 'ornament';
}
