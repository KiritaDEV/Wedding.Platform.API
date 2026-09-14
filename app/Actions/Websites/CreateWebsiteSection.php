<?php

namespace App\Actions\Websites;

use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteSectionEditorNames;
use App\Website\WebsiteSectionRegistry;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateWebsiteSection
{
    public function __construct(private readonly WebsiteSectionRegistry $sections, private readonly WebsiteTemplateRegistry $templates, private readonly WebsiteSectionEditorNames $names) {}

    public function handle(Website $website, string $type): WebsiteSection
    {
        return DB::transaction(function () use ($website, $type): WebsiteSection {
            $locked = Website::query()->lockForUpdate()->findOrFail($website->id);
            $definition = $this->sections->get($type);
            if ($definition === null || ! $definition->lifecycle->isUserOwned()) {
                throw ValidationException::withMessages(['type' => 'This Section type cannot be created.']);
            }
            if (! $definition->supports($locked->event()->firstOrFail()->type) || $this->templates->get($locked->template_key)?->supportsSection($type) !== true) {
                throw ValidationException::withMessages(['type' => 'This Section type is not supported by the Website.']);
            }
            $sortOrder = ((int) $locked->sections()->max('sort_order')) + 10;

            return $locked->sections()->create([
                'type' => $type,
                'editor_name' => $this->names->allocate($locked, $type),
                'sort_order' => $sortOrder,
                'is_enabled' => true,
                'content' => $definition->defaultContent,
                'appearance' => ['shared' => $this->templates->get($locked->template_key)?->appearanceDefaultsFor($type) ?? WebsiteSectionAppearance::DEFAULT],
            ]);
        });
    }
}
