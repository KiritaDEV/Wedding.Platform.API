<?php

namespace App\Website\Capabilities;

final readonly class ProjectColorDefaultsCapability
{
    /**
     * @param  list<string>  $headingColorIds
     * @param  list<string>  $bodyColorIds
     * @param  list<string>  $accentColorIds
     */
    public function __construct(
        public array $headingColorIds,
        public array $bodyColorIds,
        public array $accentColorIds,
    ) {}
}
