<?php

namespace App\Website\Capabilities;

final readonly class ViewportControlCapability
{
    /** @param list<array{key: string, displayName: string}> $options */
    public function __construct(
        public mixed $default,
        public array $options = [],
    ) {}
}
