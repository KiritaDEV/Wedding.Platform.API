<?php

namespace App\Website\Capabilities;

final readonly class ResolvedDesignContext
{
    public function __construct(
        public string $headingFontId,
        public string $bodyFontId,
        public string $headingColorId,
        public string $bodyColorId,
        public string $accentColorId,
    ) {}

    public static function fromProjectDefaults(ResolvedProjectDesignDefaults $defaults): self
    {
        return new self(
            $defaults->headingFontId,
            $defaults->bodyFontId,
            $defaults->headingColorId,
            $defaults->bodyColorId,
            $defaults->accentColorId,
        );
    }
}
