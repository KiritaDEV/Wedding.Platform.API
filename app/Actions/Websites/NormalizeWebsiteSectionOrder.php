<?php

namespace App\Actions\Websites;

use App\Models\Website;
use App\Website\Publishing\WebsitePublicationCoordinator;

final class NormalizeWebsiteSectionOrder
{
    public function handle(Website $website): void
    {
        app(WebsitePublicationCoordinator::class)->forDraftMutation($website, function () use ($website): void {
            $this->apply($website);
        });
    }

    private function apply(Website $website): void
    {
        $website->sections()->get()->values()->each(function ($section, int $index): void {
            $section->updateQuietly(['sort_order' => ($index + 1) * 10]);
        });
    }
}
