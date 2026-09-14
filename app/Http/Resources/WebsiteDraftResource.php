<?php

namespace App\Http\Resources;

use App\Models\MediaAsset;
use App\Models\WebsiteSection;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\WebsiteDraftNormalizer;
use App\Website\WebsiteSectionMediaReferences;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebsiteDraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $draft = app(WebsiteDraftNormalizer::class)->normalize($this->resource);
        $template = app(WebsiteTemplateRegistry::class)->get($draft['templateKey']);
        $capabilities = $template === null ? null : app(WebsiteCapabilityResolver::class)->template($template);

        return [
            'schemaVersion' => $draft['schemaVersion'],
            'id' => $draft['id'],
            'eventId' => $draft['eventId'],
            'name' => $draft['name'],
            'templateKey' => $draft['templateKey'],
            'designSettings' => $draft['designSettings'],
            'projectDesignDefaults' => $draft['projectDesignDefaults'],
            'template' => $template === null ? null : [
                'key' => $template->key,
                'displayName' => $template->displayName,
                'designOptions' => $template->designOptions,
                'capabilities' => new WebsiteTemplateCapabilitiesResource($capabilities),
            ],
            'sections' => array_map(
                fn (array $item): WebsiteSectionResource => new WebsiteSectionResource($item['section'], $item['content']),
                $draft['sections'],
            ),
            'media' => (object) $this->resolvedMedia($draft['sections']),
        ];
    }

    /**
     * @param  list<array{section: WebsiteSection, content: array<string, mixed>}>  $sections
     * @return array<string, array<string, mixed>>
     */
    private function resolvedMedia(array $sections): array
    {
        $references = app(WebsiteSectionMediaReferences::class);
        $ids = collect($sections)->flatMap(fn (array $item) => $references->extract($item['section']->type, $item['content'], $item['section']->appearance))
            ->pluck('assetId')->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return MediaAsset::query()->where('event_id', $this->event_id)->whereKey($ids)->with('variants')->get()
            ->mapWithKeys(function (MediaAsset $asset): array {
                $web = $asset->variants->firstWhere('variant_key', 'web');
                if ($web === null) {
                    return [];
                }

                return [$asset->id => [
                    'id' => $asset->id,
                    'originalFilename' => $asset->original_filename,
                    'width' => $asset->width,
                    'height' => $asset->height,
                    'web' => [
                        'width' => $web->width,
                        'height' => $web->height,
                        'url' => route('events.media.variants.show', ['event' => $this->event_id, 'asset' => $asset->id, 'variant' => 'web']),
                    ],
                ]];
            })->all();
    }
}
