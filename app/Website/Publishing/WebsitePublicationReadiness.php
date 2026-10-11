<?php

namespace App\Website\Publishing;

use Illuminate\Contracts\Container\Container;

final class WebsitePublicationReadiness
{
    public function __construct(private readonly WebsitePublicationMediaRule $media, private readonly Container $container) {}

    public function validate(PublishedWebsiteSnapshot $snapshot): void
    {
        $diagnostics = $this->media->diagnostics($snapshot);
        foreach ($this->container->tagged(WebsitePublicationRule::class) as $rule) {
            array_push($diagnostics, ...$rule->diagnostics($snapshot));
        }
        if ($diagnostics !== []) {
            throw new WebsitePublicationNotReady($diagnostics);
        }
    }
}
