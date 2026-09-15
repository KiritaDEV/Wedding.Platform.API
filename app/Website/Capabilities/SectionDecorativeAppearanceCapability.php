<?php

namespace App\Website\Capabilities;

final readonly class SectionDecorativeAppearanceCapability
{
    /**
     * @param  list<string>  $textures
     * @param  list<string>  $patterns
     * @param  list<string>  $overlays
     * @param  list<string>  $frames
     * @param  list<string>  $backgroundColorIds
     * @param  list<string>  $frameColorIds
     */
    public function __construct(
        public array $textures,
        public array $patterns,
        public array $overlays,
        public array $frames,
        public array $backgroundColorIds,
        public array $frameColorIds,
    ) {}
}
