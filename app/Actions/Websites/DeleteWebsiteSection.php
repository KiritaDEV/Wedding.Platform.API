<?php

namespace App\Actions\Websites;

use App\Models\WebsiteSection;
use App\Website\WebsiteSectionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteWebsiteSection
{
    public function __construct(private readonly WebsiteSectionRegistry $sections, private readonly NormalizeWebsiteSectionOrder $normalizeOrder) {}

    public function handle(WebsiteSection $section): void
    {
        $definition = $this->sections->get($section->type);
        if ($definition?->lifecycle->isUserOwned() !== true) {
            throw ValidationException::withMessages(['section' => 'This Section cannot be deleted.']);
        }
        DB::transaction(function () use ($section): void {
            $website = $section->website()->firstOrFail();
            $section->delete();
            $this->normalizeOrder->handle($website);
        });
    }
}
