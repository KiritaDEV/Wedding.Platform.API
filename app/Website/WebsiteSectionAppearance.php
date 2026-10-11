<?php

namespace App\Website;

final class WebsiteSectionAppearance
{
    public const RESPONSIVE_VIEWPORTS = ['tablet', 'mobile'];

    public const RESPONSIVE_SETTINGS = [
        'mediaPlacement',
        'mediaSize',
        'mediaContentGap',
        'headingAlignment',
        'bodyAlignment',
        'mediaSpacing',
        'contentPosition',
        'innerSpacing',
        'columns',
        'gap',
        'aspectRatio',
    ];

    public const DEFAULT = [
        'headingAlignment' => 'inherit',
        'bodyAlignment' => 'inherit',
        'backgroundTreatment' => 'inherit',
        'emphasis' => 'inherit',
    ];

    public const OPTIONS = [
        'headingAlignments' => [
            ['key' => 'inherit', 'displayName' => 'Use Template'],
            ['key' => 'left', 'displayName' => 'Left'],
            ['key' => 'center', 'displayName' => 'Center'],
            ['key' => 'right', 'displayName' => 'Right'],
        ],
        'bodyAlignments' => [
            ['key' => 'inherit', 'displayName' => 'Use Template'],
            ['key' => 'left', 'displayName' => 'Left'],
            ['key' => 'center', 'displayName' => 'Center'],
            ['key' => 'right', 'displayName' => 'Right'],
        ],
        'backgroundTreatments' => [
            ['key' => 'inherit', 'displayName' => 'Use Template'],
            ['key' => 'plain', 'displayName' => 'Plain'],
            ['key' => 'soft', 'displayName' => 'Soft'],
            ['key' => 'accent', 'displayName' => 'Accent'],
        ],
        'emphasisOptions' => [
            ['key' => 'inherit', 'displayName' => 'Use Template'],
            ['key' => 'standard', 'displayName' => 'Standard'],
            ['key' => 'featured', 'displayName' => 'Featured'],
            ['key' => 'subtle', 'displayName' => 'Subtle'],
        ],
    ];
}
