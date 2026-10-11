<?php

namespace App\Website\Capabilities;

enum ContainerColorRole: string
{
    case HeadingColor = 'headingColor';
    case BodyColor = 'bodyColor';
    case AccentColor = 'accentColor';
    case BackgroundColor = 'backgroundColor';
    case FrameColor = 'frameColor';
}
