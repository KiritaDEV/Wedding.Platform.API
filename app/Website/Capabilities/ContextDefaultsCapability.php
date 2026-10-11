<?php

namespace App\Website\Capabilities;

final readonly class ContextDefaultsCapability
{
    /**
     * @param  list<ContextTypographyCapability>  $typography
     * @param  list<ContextColorCapability>  $colors
     */
    public function __construct(
        public array $typography,
        public array $colors,
    ) {}
}
