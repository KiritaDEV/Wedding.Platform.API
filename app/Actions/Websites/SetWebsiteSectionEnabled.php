<?php

namespace App\Actions\Websites;

use App\Exceptions\IncompatibleWebsiteTemplate;
use App\Exceptions\UnknownWebsiteTemplate;
use App\Models\WebsiteSection;
use App\Website\WebsiteTemplateRegistry;

final class SetWebsiteSectionEnabled
{
    public function __construct(private readonly WebsiteTemplateRegistry $templates) {}

    public function handle(WebsiteSection $section, bool $isEnabled): WebsiteSection
    {
        if ($isEnabled) {
            $section->loadMissing('website.event');
            $template = $this->templates->get($section->website->template_key);

            if ($template === null) {
                throw new UnknownWebsiteTemplate($section->website->template_key);
            }

            if (! $template->supportsEventType($section->website->event->type)) {
                throw IncompatibleWebsiteTemplate::forEventType($template->key, $section->website->event->type->value);
            }

            if (! $template->supportsSection($section->type)) {
                throw IncompatibleWebsiteTemplate::forSections($template->key, [$section->type]);
            }
        }

        $section->is_enabled = $isEnabled;
        $section->save();

        return $section;
    }
}
