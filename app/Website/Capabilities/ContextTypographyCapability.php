<?php

namespace App\Website\Capabilities;

final readonly class ContextTypographyCapability
{
    /** @param list<string> $allowedFontIds */
    public function __construct(
        public TypographyRole $role,
        public array $allowedFontIds,
        public AppearanceControlScope $scope = AppearanceControlScope::Shared,
    ) {}
}
