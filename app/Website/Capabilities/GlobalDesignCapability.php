<?php

namespace App\Website\Capabilities;

final readonly class GlobalDesignCapability
{
    /** @param list<GlobalDesignControlCapability> $controls */
    public function __construct(public array $controls) {}
}
