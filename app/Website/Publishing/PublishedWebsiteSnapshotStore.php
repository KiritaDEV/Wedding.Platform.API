<?php

namespace App\Website\Publishing;

use App\Models\Website;
use Carbon\CarbonImmutable;
use RuntimeException;

/** One replaceable snapshot in the project row; no draft fallback or publication orchestration. */
final class PublishedWebsiteSnapshotStore
{
    public function __construct(private readonly PublishedWebsiteSnapshotReader $reader, private readonly WebsitePublicationCoordinator $coordinator) {}

    public function read(Website $project): ?PublishedWebsiteSnapshot
    {
        $saved = $project->newQuery()->select(['id', 'event_id', 'template_key', 'published_snapshot'])->findOrFail($project->getKey());
        $json = $saved->getRawOriginal('published_snapshot');
        if ($json === null) {
            return null;
        }
        $snapshot = $this->reader->decode($json);
        $this->assertProject($saved, $snapshot);

        return $snapshot;
    }

    public function replace(Website $project, PublishedWebsiteSnapshot $candidate): PublishedWebsiteSnapshot
    {
        $snapshot = $this->reader->decode($candidate->toJson());

        return $this->coordinator->forProject($project, function ($event, Website $saved) use ($snapshot): PublishedWebsiteSnapshot {
            $this->assertProject($saved, $snapshot);
            $saved->forceFill([
                'published_snapshot' => $snapshot->jsonSerialize(),
                'snapshot_published_at' => CarbonImmutable::now('UTC'),
            ]);
            if (! $saved->save()) {
                throw new RuntimeException('Snapshot replacement was cancelled.');
            }

            return $snapshot;
        });
    }

    private function assertProject(Website $project, PublishedWebsiteSnapshot $snapshot): void
    {
        $tree = $snapshot->jsonSerialize();
        if ($tree->project->id !== $project->id || $tree->project->eventId !== $project->event_id || $tree->website->templateKey !== $project->template_key) {
            throw new InvalidPublishedWebsiteSnapshot('Snapshot belongs to a different Website Project.');
        }
    }
}
