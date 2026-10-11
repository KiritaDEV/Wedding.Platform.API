<?php

namespace App\Website\Capabilities;

final readonly class ProjectTypographyDefaultsCapability
{
    /**
     * @param  list<string>  $headingFontIds
     * @param  list<string>  $bodyFontIds
     */
    public function __construct(
        public array $headingFontIds,
        public array $bodyFontIds,
    ) {}
}
