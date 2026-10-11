<?php

namespace App\Website;

enum WebsiteSectionLifecycle: string
{
    case RequiredSingleton = 'requiredSingleton';
    case UserOwnedRepeatable = 'userOwnedRepeatable';

    public function isSingleton(): bool
    {
        return $this === self::RequiredSingleton;
    }

    public function isRequired(): bool
    {
        return $this === self::RequiredSingleton;
    }

    public function isUserOwned(): bool
    {
        return $this === self::UserOwnedRepeatable;
    }
}
