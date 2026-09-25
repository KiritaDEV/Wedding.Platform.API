<?php

namespace App\Website;

use App\Http\Resources\WebsiteSectionResource;
use App\Http\Resources\WebsiteTemplateCapabilitiesResource;
use App\Models\MediaAsset;
use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use Illuminate\Http\Request;

final readonly class RenderableWebsite
{
    public function __construct(private Website $website) {}

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $renderable = app(WebsiteDraftNormalizer::class)->normalize($this->website);
        $template = app(WebsiteTemplateRegistry::class)->get($renderable['templateKey']);
        $capabilities = $template === null ? null : app(WebsiteCapabilityResolver::class)->template($template);

        return [
            'schemaVersion' => $renderable['schemaVersion'],
            'id' => $renderable['id'],
            'templateKey' => $renderable['templateKey'],
            'designSettings' => $renderable['designSettings'],
            'projectDesignDefaults' => $renderable['projectDesignDefaults'],
            'template' => $template === null ? null : [
                'key' => $template->key,
                'displayName' => $template->displayName,
                'designOptions' => $template->designOptions,
                'capabilities' => (new WebsiteTemplateCapabilitiesResource($capabilities))->resolve($request),
            ],
            'sections' => array_map(
                fn (array $item): array => (new WebsiteSectionResource($item['section'], $item['content']))->resolve($request),
                $renderable['sections'],
            ),
            'media' => (object) $this->resolvedMedia($renderable['sections']),
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

        return MediaAsset::query()->where('event_id', $this->website->event_id)->whereKey($ids)->with('variants')->get()
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
                        'url' => route('public.events.media.web', [
                            'slug' => $this->website->event->slug,
                            'asset' => $asset->id,
                        ]),
                    ],
                ]];
            })->all();
    }
}
