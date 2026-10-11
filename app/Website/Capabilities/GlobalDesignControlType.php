<?php

namespace App\Website\Capabilities;

enum GlobalDesignControlType: string
{
    case PalettePreset = 'palettePreset';
    case TypographyPairing = 'typographyPairing';
    case ArtStyle = 'artStyle';
}
