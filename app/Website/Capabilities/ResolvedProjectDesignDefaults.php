<?php

namespace App\Website\Capabilities;

/**
 * Derived from template presets today. Future persisted overrides remain sparse:
 * an absent override means use this template-derived value.
 */
final readonly class ResolvedProjectDesignDefaults
{
    public function __construct(
        public string $headingFontId,
        public string $bodyFontId,
        public string $headingColorId,
        public string $bodyColorId,
        public string $accentColorId,
    ) {}
}
