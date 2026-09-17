<?php

namespace App\Actions\Websites;

use App\Models\Website;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteSectionRegistry;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InitializeWebsiteSections
{
    public function __construct(
        private readonly WebsiteSectionRegistry $registry,
        private readonly WebsiteTemplateRegistry $templates,
    ) {}

    public function handle(Website $website, bool $enableMissingSections = true): void
    {
        DB::transaction(function () use ($website, $enableMissingSections): void {
            $website->loadMissing('event');
            $definitions = $this->registry->defaultCompositionFor($website->event->type);
            $template = $this->templates->get($website->template_key);
            $existingTypes = $website->sections()->pluck('type')->all();
            $timestamp = now();
            $rows = [];

            foreach ($definitions as $definition) {
                if (in_array($definition->key, $existingTypes, true)) {
                    continue;
                }

                $content = $definition->key === 'hero'
                    ? $this->initialHeroContent($website->event->name)
                    : $definition->defaultContent;
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'website_id' => $website->getKey(),
                    'type' => $definition->key,
                    'singleton_key' => $definition->lifecycle->isSingleton() ? $definition->key : null,
                    'editor_name' => null,
                    'sort_order' => $definition->defaultOrder,
                    'is_enabled' => $enableMissingSections && $definition->defaultEnabled,
                    'content' => json_encode($content, JSON_THROW_ON_ERROR),
                    'appearance' => json_encode($this->initialAppearance(
                        $definition->key,
                        $template?->appearanceDefaultsFor($definition->key) ?? WebsiteSectionAppearance::DEFAULT,
                    ), JSON_THROW_ON_ERROR),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            if ($rows !== []) {
                DB::table('website_sections')->insertOrIgnore($rows);
            }
        });
    }

    private function initialAppearance(string $sectionType, array $appearance): array
    {
        return in_array($sectionType, ['hero', 'blank'], true) ? ['shared' => $appearance] : $appearance;
    }

    /** @return array<string, mixed> */
    private function initialHeroContent(string $eventName): array
    {
        $headlineId = (string) Str::ulid();
        $dateId = (string) Str::ulid();
        $supportingId = (string) Str::ulid();

        return ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => [
            'elements' => [
                ['id' => $headlineId, 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => $eventName]]]]], 'appearance' => ['fontSize' => 'xl', 'fontWeight' => 700, 'alignment' => 'center']],
                ['id' => $dateId, 'type' => 'date', 'editorName' => 'Date 1', 'appearance' => ['alignment' => 'center']],
                ['id' => $supportingId, 'type' => 'text', 'editorName' => 'Text 2', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Together with their families']]]]], 'appearance' => ['alignment' => 'center']],
            ],
            'order' => array_map(fn (string $id): array => ['kind' => 'element', 'id' => $id], [$headlineId, $dateId, $supportingId]),
        ]]]];
    }
}
