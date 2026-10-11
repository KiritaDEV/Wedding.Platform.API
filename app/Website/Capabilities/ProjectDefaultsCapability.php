<?php

namespace App\Website\Capabilities;

final readonly class ProjectDefaultsCapability
{
    public function __construct(
        public ProjectTypographyDefaultsCapability $typography,
        public ProjectColorDefaultsCapability $colors,
    ) {}
}
