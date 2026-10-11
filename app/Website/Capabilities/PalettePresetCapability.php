<?php

namespace App\Website\Capabilities;

final readonly class PalettePresetCapability
{
    /** @param array<string, string> $roles */
    public function __construct(
        public string $id,
        public string $displayName,
        public array $roles,
    ) {}
}
