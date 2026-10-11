<?php

namespace App\Website\Capabilities;

final readonly class GlobalDesignControlCapability
{
    /** @param list<array{key: string, displayName: string}> $options */
    public function __construct(
        public GlobalDesignControlId $id,
        public GlobalDesignControlType $type,
        public string $default,
        public array $options,
    ) {}
}
