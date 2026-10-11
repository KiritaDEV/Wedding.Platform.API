<?php

namespace App\Website\Capabilities;

use App\Website\Elements\WebsiteElementType;

final readonly class ElementCapability
{
    public function __construct(
        public WebsiteElementType $type,
        public ?ElementAppearanceCapability $appearance,
    ) {}
}
