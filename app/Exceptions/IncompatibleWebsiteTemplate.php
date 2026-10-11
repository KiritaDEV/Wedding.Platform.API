<?php

namespace App\Exceptions;

use DomainException;

final class IncompatibleWebsiteTemplate extends DomainException
{
    public static function forEventType(string $templateKey, string $eventType): self
    {
        return new self("Website template [{$templateKey}] does not support Event type [{$eventType}].");
    }

    /** @param list<string> $sectionTypes */
    public static function forSections(string $templateKey, array $sectionTypes): self
    {
        return new self(sprintf(
            'Website template [%s] does not support enabled Section types [%s].',
            $templateKey,
            implode(', ', $sectionTypes),
        ));
    }
}
