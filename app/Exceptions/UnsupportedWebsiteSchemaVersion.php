<?php

namespace App\Exceptions;

use DomainException;

final class UnsupportedWebsiteSchemaVersion extends DomainException
{
    public const CODE = 'website_schema_version_unsupported';

    public const PUBLIC_MESSAGE = 'This Website Project uses an unsupported schema version.';

    public function __construct(
        public readonly int $encounteredVersion,
        public readonly int $currentVersion,
    ) {
        parent::__construct(
            "Website schema version [{$encounteredVersion}] is not supported; current version is [{$currentVersion}].",
        );
    }
}
