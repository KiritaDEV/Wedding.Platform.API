<?php

namespace App\Actions\Websites;

use App\Models\Event;
use App\Models\Website;
use App\Website\Publishing\InvalidPublishedWebsiteSnapshot;
use App\Website\Publishing\PublishedWebsiteSnapshotBuilder;
use App\Website\Publishing\PublishedWebsiteSnapshotStore;
use App\Website\Publishing\WebsitePublicationCoordinator;
use App\Website\Publishing\WebsitePublicationNotReady;
use App\Website\Publishing\WebsitePublicationReadiness;
use Illuminate\Validation\ValidationException;

final class PublishWebsite
{
    public function __construct(
        private readonly WebsitePublicationCoordinator $coordinator,
        private readonly PublishedWebsiteSnapshotBuilder $builder,
        private readonly PublishedWebsiteSnapshotStore $snapshots,
        private readonly WebsitePublicationReadiness $readiness,
    ) {}

    public function handle(Event $event, Website $website): Website
    {
        if ($website->event_id !== $event->id) {
            throw ValidationException::withMessages(['website' => 'The Website Project must belong to this Event.']);
        }

        $this->coordinator->forEvent($event, function (Event $lockedEvent) use ($website): void {
            $scoped = Website::query()->where('event_id', $lockedEvent->id)->findOrFail($website->id);
            $this->coordinator->forProject($scoped, function (Event $projectEvent, Website $project) use ($lockedEvent): void {
                if ($projectEvent->id !== $lockedEvent->id) {
                    throw ValidationException::withMessages(['website' => 'The Website Project must belong to this Event.']);
                }
                try {
                    $candidate = $this->builder->buildLocked($project);
                } catch (ValidationException $exception) {
                    $diagnostics = [];
                    foreach ($exception->errors() as $path => $messages) {
                        foreach ($messages as $message) {
                            $sectionId = preg_match('/^sections\.([^.]+)\./', $path, $matches) === 1 ? $matches[1] : null;
                            $diagnostics[] = ['code' => 'draft_invalid', 'message' => $message, 'projectId' => $project->id, 'sectionId' => $sectionId, 'path' => $path];
                        }
                    }
                    throw new WebsitePublicationNotReady($diagnostics);
                }
                $this->readiness->validate($candidate);
                try {
                    $this->snapshots->replace($project, $candidate);
                } catch (InvalidPublishedWebsiteSnapshot) {
                    throw new WebsitePublicationNotReady([['code' => 'snapshot_invalid', 'message' => 'The snapshot candidate is invalid.', 'projectId' => $project->id]]);
                }
                $lockedEvent->published_website_id = $project->id;
                if (! $lockedEvent->save()) {
                    throw new \RuntimeException('Publication pointer update was cancelled.');
                }
            });
        });

        return $website->refresh();
    }
}
