<?php

namespace App\Website\Publishing;

use App\Models\MediaAsset;
use App\Models\Website;
use App\Website\WebsiteSectionMediaReferenceExtractor;
use Illuminate\Validation\ValidationException;

/** Retained references, including hidden/disabled content and inactive projects. */
final class PublishedWebsiteMediaUsage
{
    public function __construct(private readonly PublishedWebsiteSnapshotStore $snapshots, private readonly WebsiteSectionMediaReferenceExtractor $references) {}

    public function referencesFor(MediaAsset $asset): array
    {
        return array_values(array_filter($this->referencesForEvents([$asset->event_id]),
            fn (array $reference): bool => $reference['mediaId'] === $asset->id));
    }

    public function referencesForEvents(array $eventIds): array
    {
        $references = [];
        foreach (Website::query()->whereIn('event_id', $eventIds)->whereNotNull('published_snapshot')->orderBy('id')->get(['id', 'event_id']) as $project) {
            try {
                $snapshot = $this->snapshots->read($project);
            } catch (InvalidPublishedWebsiteSnapshot) {
                throw ValidationException::withMessages(['asset' => 'Published media usage could not be verified. Republish or remove the project with the malformed snapshot before managing image deletion.']);
            }
            if ($snapshot === null) {
                continue;
            }
            $data = json_decode($snapshot->toJson(), true, 512, JSON_THROW_ON_ERROR);
            foreach ($data['website']['sections'] as &$section) {
                $owned = array_values(array_filter($data['sharedContentDefinitions'], fn (array $definition): bool => $definition['sectionId'] === $section['id']));
                foreach ($owned as &$definition) {
                    unset($definition['sectionId']);
                }
                unset($definition);
                if ($owned !== []) {
                    $section['content']['sharedContentDefinitions'] = $owned;
                }
            }
            unset($section);
            foreach ($data['website']['sections'] as $section) {
                foreach ($this->references->extract($section['id'], $section['type'], $section['content'], $section['appearance']) as $reference) {
                    $references[] = [
                        'source' => 'publishedSnapshot',
                        'mediaId' => $reference['mediaId'], 'eventId' => $project->event_id,
                        'websiteProjectId' => $project->id, 'websiteProjectName' => $data['project']['name'],
                        'sectionId' => $section['id'], 'sectionType' => $section['type'],
                        'sectionName' => $section['displayName'],
                        'reference' => $reference['reference'],
                    ];
                }
            }
        }

        return $references;
    }
}
