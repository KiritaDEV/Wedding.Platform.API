<?php

namespace App\Website\Publishing;

use App\Http\Resources\WebsiteSectionResource;
use App\Http\Resources\WebsiteTemplateCapabilitiesResource;
use App\Models\Website;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\WebsiteDraftNormalizer;
use App\Website\WebsiteSectionMediaReferences;
use App\Website\WebsiteTemplateRegistry;

final class PublishedWebsiteSnapshotBuilder
{
    public function __construct(
        private readonly WebsiteDraftNormalizer $drafts,
        private readonly WebsiteTemplateRegistry $templates,
        private readonly WebsiteCapabilityResolver $capabilities,
        private readonly PublishedWebsiteDraftValidator $validator,
        private readonly WebsiteSectionMediaReferences $media,
    ) {}

    /**
     * Reads saved state, never the caller's dirty attributes or cached relations.
     * Future publish orchestration must supply its own consistent-read transaction.
     */
    public function build(Website $project): PublishedWebsiteSnapshot
    {
        $saved = Website::query()->with(['event', 'sections'])->findOrFail($project->getKey());

        return $this->project($saved);
    }

    /** Uses the current-read rows acquired by WebsitePublicationCoordinator, not a repeatable-read view. */
    public function buildLocked(Website $saved): PublishedWebsiteSnapshot
    {
        if ($saved->getConnection()->transactionLevel() === 0 || ! $saved->relationLoaded('event') || ! $saved->relationLoaded('sections')) {
            throw new \LogicException('Locked snapshot projection requires a coordinated loaded aggregate.');
        }

        return $this->project($saved);
    }

    private function project(Website $saved): PublishedWebsiteSnapshot
    {
        $this->validator->validate($saved);
        $template = $this->templates->get($saved->template_key);

        $draft = $this->drafts->normalize($saved);
        $assetIds = [];
        $sections = [];
        $definitions = [];
        foreach ($draft['sections'] as $item) {
            $section = $item['section'];
            $presentation = (new WebsiteSectionResource($section, $item['content']))->presentation();
            foreach ($item['content']['sharedContentDefinitions'] ?? [] as $definition) {
                $definitions[] = ['sectionId' => $section->id, ...$definition];
            }
            unset($presentation['content']['sharedContentDefinitions']);
            $sections[] = $presentation;
            foreach ($this->media->extract($section->type, $item['content'], $section->appearance) as $reference) {
                $assetIds[] = $reference['assetId'];
            }
        }
        $assetIds = array_values(array_unique($assetIds));
        sort($assetIds, SORT_STRING);
        $event = $saved->event;

        return new PublishedWebsiteSnapshot(
            project: ['id' => $saved->id, 'eventId' => $saved->event_id, 'name' => $saved->name],
            event: [
                'id' => $event->id,
                'name' => $event->name,
                'slug' => $event->slug,
                'type' => $event->type->value,
                'eventDate' => $event->event_date?->toDateString(),
                'startTime' => $event->start_time === null ? null : substr($event->start_time, 0, 5),
                'timeZone' => $event->time_zone,
                'startsAtUtc' => $event->startsAtUtc()?->format('Y-m-d\TH:i:s\Z'),
            ],
            website: [
                'schemaVersion' => $draft['schemaVersion'],
                'id' => $draft['id'],
                'templateKey' => $draft['templateKey'],
                'designSettings' => $draft['designSettings'],
                'projectDesignDefaults' => $draft['projectDesignDefaults'],
                'template' => [
                    'key' => $template->key,
                    'displayName' => $template->displayName,
                    'designOptions' => $template->designOptions,
                    'capabilities' => (new WebsiteTemplateCapabilitiesResource($this->capabilities->template($template)))->presentation(),
                ],
                'sections' => $sections,
            ],
            mediaAssetIds: $assetIds,
            sharedContentDefinitions: $definitions,
        );
    }
}
