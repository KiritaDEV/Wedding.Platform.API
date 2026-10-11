<?php

namespace App\Actions\Websites;

use App\Exceptions\IncompatibleWebsiteTemplate;
use App\Exceptions\UnknownWebsiteTemplate;
use App\Exceptions\WebsiteAlreadyInitialized;
use App\Models\Event;
use App\Models\Website;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\WebsiteSchema;
use App\Website\WebsiteSectionRegistry;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class InitializeEventWebsite
{
    public function __construct(
        private readonly WebsiteTemplateRegistry $templates,
        private readonly WebsiteCapabilityResolver $capabilities,
        private readonly WebsiteSectionRegistry $sections,
        private readonly InitializeWebsiteSections $initializeSections,
    ) {}

    public function handle(Event $event, string $templateKey): Website
    {
        try {
            return DB::transaction(function () use ($event, $templateKey): Website {
                $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->getKey());
                if ($lockedEvent->website()->exists()) {
                    throw new WebsiteAlreadyInitialized;
                }

                $template = $this->templates->get($templateKey);
                if ($template === null || ! $template->enabled) {
                    throw new UnknownWebsiteTemplate($templateKey);
                }
                if (! $template->supportsEventType($lockedEvent->type)) {
                    throw IncompatibleWebsiteTemplate::forEventType($templateKey, $lockedEvent->type->value);
                }

                $definitions = $this->sections->defaultCompositionFor($lockedEvent->type);
                $unsupported = array_values(array_filter(
                    array_keys($definitions),
                    fn (string $sectionType): bool => ! $template->supportsSection($sectionType),
                ));
                if ($unsupported !== []) {
                    throw IncompatibleWebsiteTemplate::forSections($templateKey, $unsupported);
                }

                $website = $lockedEvent->website()->create([
                    'name' => Website::DEFAULT_NAME,
                    'template_key' => $template->key,
                    'design_settings' => $this->capabilities->designSettingsForStorage($template, []),
                    'schema_version' => WebsiteSchema::CURRENT_SCHEMA_VERSION,
                ]);
                $this->initializeSections->handle($website);

                return $website;
            });
        } catch (QueryException $exception) {
            if ($event->website()->exists()) {
                throw new WebsiteAlreadyInitialized;
            }
            throw $exception;
        }
    }
}
