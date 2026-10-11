<?php

namespace App\Website\Capabilities;

final readonly class TypographyPresetCapability
{
    public function __construct(
        public string $id,
        public string $displayName,
        public string $headingFontId,
        public string $bodyFontId,
    ) {}
}
