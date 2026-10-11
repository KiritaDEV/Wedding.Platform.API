<?php

namespace App\Exceptions;

use App\Actions\Media\MediaAssetUsage;
use DomainException;

final class MediaAssetInUse extends DomainException
{
    public const CODE = 'media_asset_in_use';

    public const PUBLIC_MESSAGE = 'This image is used by one or more Website Projects.';

    public function __construct(public readonly MediaAssetUsage $usage)
    {
        parent::__construct(self::PUBLIC_MESSAGE);
    }
}
