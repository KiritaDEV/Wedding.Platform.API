<?php

namespace App\Website\Capabilities;

final readonly class ElementColorCapability
{
    /** @param list<string> $allowedColorIds */
    public function __construct(
        public ElementColorRole $role,
        public array $allowedColorIds,
        public AppearanceControlScope $scope = AppearanceControlScope::Shared,
    ) {}
}
