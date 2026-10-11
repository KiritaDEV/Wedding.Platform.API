<?php

namespace App\Exceptions;

use DomainException;

final class UnknownWebsiteTemplate extends DomainException
{
    public function __construct(string $templateKey)
    {
        parent::__construct("Unknown Website template [{$templateKey}].");
    }
}
