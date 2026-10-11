<?php

namespace App\Website\Publishing;

use App\Models\Event;
use App\Models\Website;
use App\Models\WebsiteSection;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lock order: Event -> Website -> Sections (id order) -> Media assets/variants (id order).
 * Persisted draft actions join this boundary; validation-only calls do not acquire locks.
 * Nested draft actions join the outer mutation and share its revision.
 */
final class WebsitePublicationCoordinator
{
    /** @var array<string, bool> */
    private array $draftMutations = [];

    public function forDraftMutation(Website|WebsiteSection $target, Closure $operation): mixed
    {
        return $this->forProject($target, function (Event $event, Website $project) use ($operation): mixed {
            if (isset($this->draftMutations[$project->id])) {
                return $operation($event, $project);
            }

            $this->draftMutations[$project->id] = true;
            try {
                // Tentative until commit: validation/operation failures roll this back too.
                $project->draft_revision = (string) Str::ulid();
                $project->saveOrFail();

                return $operation($event, $project);
            } finally {
                unset($this->draftMutations[$project->id]);
            }
        });
    }

    public function forEvent(Event $event, Closure $operation): mixed
    {
        return DB::transaction(function () use ($event, $operation): mixed {
            $locked = Event::query()->lockForUpdate()->findOrFail($event->id);

            return $operation($locked);
        });
    }

    public function forProject(Website|WebsiteSection $target, Closure $operation): mixed
    {
        $projectId = $target instanceof Website ? $target->id : $target->website_id;
        $eventId = Website::query()->whereKey($projectId)->value('event_id');
        $event = Event::query()->findOrFail($eventId);

        return $this->forEvent($event, function (Event $lockedEvent) use ($target, $projectId, $operation): mixed {
            $project = Website::query()->where('event_id', $lockedEvent->id)->lockForUpdate()->findOrFail($projectId);
            $sections = $project->sections()->reorder('id')->lockForUpdate()->get();
            $project->setRelation('event', $lockedEvent)->setRelation('sections', $sections->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values());
            // A locking read is authoritative even under MySQL REPEATABLE READ.
            // Refreshing with a non-locking read can return an older transaction view.
            $current = $target instanceof Website ? $project : $sections->firstWhere('id', $target->id);
            if ($current === null) {
                throw (new ModelNotFoundException)->setModel(WebsiteSection::class, [$target->id]);
            }
            $target->setRawAttributes($current->getAttributes(), true)->unsetRelations();

            return $operation($lockedEvent, $project);
        });
    }
}
