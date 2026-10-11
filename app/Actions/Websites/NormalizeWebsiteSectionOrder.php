<?php

namespace App\Actions\Websites;

use App\Models\Website;

final class NormalizeWebsiteSectionOrder
{
    public function handle(Website $website): void
    {
        $website->sections()->get()->values()->each(function ($section, int $index): void {
            $section->updateQuietly(['sort_order' => ($index + 1) * 10]);
        });
    }
}
