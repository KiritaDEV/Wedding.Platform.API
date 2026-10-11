<?php

namespace App\Website\Capabilities;

final readonly class DesignColorCapability
{
    /**
     * @param  list<ProjectColorRole>  $allowedProjectRoles
     * @param  list<ElementColorRole>  $allowedElementRoles
     * @param  list<ContainerColorRole>  $allowedContainerRoles
     */
    public function __construct(
        public string $id,
        public string $displayName,
        public string $value,
        public array $allowedProjectRoles,
        public array $allowedElementRoles,
        public array $allowedContainerRoles,
    ) {}
}
