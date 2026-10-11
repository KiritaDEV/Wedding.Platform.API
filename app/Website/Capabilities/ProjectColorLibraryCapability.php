<?php

namespace App\Website\Capabilities;

final readonly class ProjectColorLibraryCapability
{
    public function __construct(
        public bool $enabled,
        public int $maximum,
        public string $format,
    ) {}
}
