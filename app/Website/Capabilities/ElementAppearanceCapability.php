<?php

namespace App\Website\Capabilities;

final readonly class ElementAppearanceCapability
{
    /**
     * @param  list<ElementTypographyCapability>  $typography
     * @param  list<ElementColorCapability>  $colors
     */
    public function __construct(
        public array $typography,
        public array $colors,
    ) {}
}
