<?php

namespace App\Website\Capabilities;

final readonly class SectionCapability
{
    /**
     * @param  list<AppearanceControlCapability>  $appearanceControls
     * @param  list<PresentationCapability>  $presentations
     * @param  list<string>|null  $allowedElementTypes
     */
    public function __construct(
        public string $id,
        public array $appearanceControls,
        public ?string $defaultPresentation,
        public array $presentations,
        public ContextDefaultsCapability $contextDefaults,
        public ?array $allowedElementTypes = null,
        public ?int $maximumElementCount = null,
        public null $compositionGroups = null,
        public ?SectionDecorativeAppearanceCapability $decorativeAppearance = null,
    ) {}
}
