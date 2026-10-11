<?php

namespace App\Exceptions;

use DomainException;

final class WebsiteAlreadyInitialized extends DomainException
{
    public function __construct()
    {
        parent::__construct('This Event already has a Website.');
    }
}
