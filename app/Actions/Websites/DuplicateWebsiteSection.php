<?php

namespace App\Actions\Websites;

use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\Elements\WebsiteElementIdentityRegenerator;
use App\Website\WebsiteSectionEditorNames;
use App\Website\WebsiteSectionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DuplicateWebsiteSection
{
    public function __construct(private readonly WebsiteSectionRegistry $sections, private readonly WebsiteSectionEditorNames $names, private readonly WebsiteElementIdentityRegenerator $identities, private readonly NormalizeWebsiteSectionOrder $normalizeOrder) {}

    public function handle(WebsiteSection $source): WebsiteSection
    {
        if ($this->sections->get($source->type)?->lifecycle->isUserOwned() !== true) {
            throw ValidationException::withMessages(['section' => 'This Section cannot be duplicated.']);
        }

        return DB::transaction(function () use ($source): WebsiteSection {
            $website = Website::query()->lockForUpdate()->findOrFail($source->website_id);
            $content = $this->identities->regenerateSectionContent($source->content);
            $website->sections()->where('sort_order', '>', $source->sort_order)->increment('sort_order', 10);
            $duplicate = $website->sections()->create([
                'type' => $source->type,
                'editor_name' => $this->names->allocate($website, $source->type),
                'sort_order' => $source->sort_order + 10,
                'is_enabled' => $source->is_enabled,
                'content' => $content,
                'appearance' => $source->appearance,
            ]);
            $this->normalizeOrder->handle($website);

            return $duplicate->refresh();
        });
    }
}
