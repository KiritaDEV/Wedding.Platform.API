<?php

namespace App\Website\Capabilities;

final readonly class FontFamilyCapability
{
    /** @param list<TypographyRole> $allowedRoles */
    public function __construct(
        public string $id,
        public string $displayName,
        public array $allowedRoles,
        public string $family = '',
        public string $category = 'legacy',
        public array $source = ['type' => 'legacyAlias'],
        public string $fallback = '',
        public array $weights = [400],
        public array $styles = ['normal'],
        public array $recommendedRoles = [],
        public array $license = ['id' => 'system'],
    ) {}
}
