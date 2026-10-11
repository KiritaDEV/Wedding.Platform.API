<?php

namespace App\Website\Capabilities;

final readonly class PresentationCapability
{
    /** @param list<AppearanceControlCapability> $appearanceControls */
    public function __construct(
        public string $id,
        public string $displayName,
        public string $description,
        public string $preview,
        public array $appearanceControls,
        public ?ContextDefaultsCapability $contextDefaults = null,
    ) {}
}
