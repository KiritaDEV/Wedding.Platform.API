<?php

namespace App\Website\Capabilities;

final readonly class ContextColorCapability
{
    /** @param list<string> $allowedColorIds */
    public function __construct(
        public ContainerColorRole $role,
        public array $allowedColorIds,
        public AppearanceControlScope $scope = AppearanceControlScope::Shared,
    ) {}
}
