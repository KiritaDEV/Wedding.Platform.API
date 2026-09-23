<?php

namespace App\Http\Resources;

use App\Website\WebsiteSectionMediaReferences;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicEventSiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $website = $this->publishedWebsite;
        if ($website === null) {
            return [
                'status' => 'unpublished',
                'event' => ['name' => $this->name, 'slug' => $this->slug],
                'website' => null,
            ];
        }

        $draft = (new WebsiteDraftResource($website))->resolve($request);
        unset($draft['eventId'], $draft['name']);
        $sections = array_map(
            static fn ($section): array => $section instanceof JsonResource ? $section->resolve($request) : $section,
            $draft['sections'],
        );
        $draft['sections'] = array_values(array_filter(
            $sections,
            static fn (array $section): bool => $section['type'] !== 'rsvp' && $section['isEnabled'],
        ));
        $publicMediaIds = $website->sections
            ->where('is_enabled', true)
            ->where('type', '!=', 'rsvp')
            ->flatMap(fn ($section) => app(WebsiteSectionMediaReferences::class)->extract($section->type, $section->content, $section->appearance))
            ->pluck('assetId')->unique();
        $draft['media'] = array_intersect_key((array) $draft['media'], array_fill_keys($publicMediaIds->all(), true));
        foreach ($draft['media'] as $assetId => &$asset) {
            $asset['web']['url'] = route('public.events.media.web', [
                'slug' => $this->slug,
                'asset' => $assetId,
            ]);
        }
        unset($asset);

        return [
            'status' => 'published',
            'event' => [
                'id' => $this->id,
                'name' => $this->name,
                'slug' => $this->slug,
                'type' => $this->type->value,
                'eventDate' => $this->event_date?->toDateString(),
                'startTime' => $this->start_time === null ? null : substr($this->start_time, 0, 5),
                'timeZone' => $this->time_zone,
            ],
            'website' => $draft,
        ];
    }
}
