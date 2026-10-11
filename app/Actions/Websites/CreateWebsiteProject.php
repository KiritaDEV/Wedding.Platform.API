<?php

namespace App\Actions\Websites;

use App\Exceptions\IncompatibleWebsiteTemplate;
use App\Exceptions\UnknownWebsiteTemplate;
use App\Models\Event;
use App\Models\Website;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\WebsiteCreationTemplateCatalog;
use App\Website\WebsiteSchema;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Support\Facades\DB;

final class CreateWebsiteProject
{
    public function __construct(
        private readonly WebsiteTemplateRegistry $templates,
        private readonly WebsiteCapabilityResolver $capabilities,
        private readonly WebsiteCreationTemplateCatalog $creationTemplates,
        private readonly InitializeWebsiteSections $initializeSections,
    ) {}

    public function handle(Event $event, string $name, string $templateKey): Website
    {
        return DB::transaction(function () use ($event, $name, $templateKey): Website {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->getKey());
            $template = $this->templates->get($templateKey);

            if ($template === null || ! $template->enabled) {
                throw new UnknownWebsiteTemplate($templateKey);
            }
            if (! $template->supportsEventType($lockedEvent->type)) {
                throw IncompatibleWebsiteTemplate::forEventType($templateKey, $lockedEvent->type->value);
            }

            $unsupported = $this->creationTemplates->unsupportedSectionTypes($template, $lockedEvent->type);
            if ($unsupported !== []) {
                throw IncompatibleWebsiteTemplate::forSections($templateKey, $unsupported);
            }

            $website = $lockedEvent->websiteProjects()->create([
                'name' => trim($name),
                'template_key' => $template->key,
                'design_settings' => $this->capabilities->designSettingsForStorage($template, []),
                'schema_version' => WebsiteSchema::CURRENT_SCHEMA_VERSION,
            ]);
            $this->initializeSections->handle($website);

            return $website;
        });
    }
}
