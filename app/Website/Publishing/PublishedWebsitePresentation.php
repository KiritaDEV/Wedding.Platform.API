<?php

namespace App\Website\Publishing;

use App\Models\Event;
use App\Models\MediaAsset;
use App\Website\ScheduleContent;
use App\Website\SharedContent;
use App\Website\WebsiteSectionMediaReferences;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Guest presentation and media authorization share the same filtered snapshot. */
final class PublishedWebsitePresentation
{
    public function __construct(private readonly PublishedWebsiteSnapshotReader $reader, private readonly WebsiteSectionMediaReferences $references) {}

    public function resolve(Event $event, bool $private = false): ?stdClass
    {
        // One statement observes the active pointer and payload together. No draft Section query.
        $row = DB::table('events')->leftJoin('websites', 'websites.id', '=', 'events.published_website_id')
            ->where('events.id', $event->id)
            ->select(['events.published_website_id', 'websites.event_id', 'websites.published_snapshot'])->first();
        abort_if($row === null, 404);
        if ($row->published_website_id === null) {
            return null;
        }
        abort_if($row->event_id !== $event->id || $row->published_snapshot === null, 404);
        try {
            $tree = $this->reader->decode($row->published_snapshot)->jsonSerialize();
        } catch (InvalidPublishedWebsiteSnapshot) {
            abort(404);
        }
        abort_if($tree->project->id !== $row->published_website_id || $tree->event->id !== $event->id, 404);
        $tree->website->sections = array_values(array_filter($tree->website->sections,
            fn (stdClass $section): bool => $section->isEnabled && ($private || $section->type !== 'rsvp')));
        foreach ($tree->website->sections as $section) {
            $branches = [$section->content->compositions->shared, ...array_values(get_object_vars($section->content->compositions->custom ?? new stdClass))];
            foreach ($branches as $branch) {
                $flow = $branch->childFlow;
                $flow->elements = $this->visibleElements($flow->elements);
                $ids = array_column($flow->elements, 'id');
                $flow->order = array_values(array_filter($flow->order,
                    fn (stdClass $reference): bool => $reference->kind !== 'element' || in_array($reference->id, $ids, true)));
            }
        }

        // Only definitions referenced by visible placements reach guests.
        foreach ($tree->website->sections as $section) {
            $content = json_decode(json_encode($section->content, JSON_THROW_ON_ERROR), true);
            $placements = app(SharedContent::class)->elements($content);
            $used = array_column($placements, 'contentId');
            $definitions = array_values(array_filter($tree->sharedContentDefinitions, fn (object $definition): bool => $definition->sectionId === $section->id && in_array($definition->id, $used, true)));
            if ($definitions !== []) {
                foreach ($definitions as $index => $definition) {
                    $definitions[$index] = clone $definition;
                    unset($definitions[$index]->sectionId);
                    $definitions[$index]->content = clone $definition->content;
                    // A shared field may reach guests only if a visible referencing placement shows it.
                    // Preserve the definition identity and content needed by sibling/device placements.
                    $visibility = ['showDescriptions' => false, 'showLocations' => false, 'showMapLinks' => false];
                    foreach ($placements as $placement) {
                        if (($placement['contentId'] ?? null) !== $definition->id) {
                            continue;
                        }
                        foreach ($visibility as $key => $shown) {
                            $visibility[$key] = $shown || ($placement['appearance'][$key] ?? true);
                        }
                    }
                    $definitions[$index]->content->days = $this->visibleDays($definition->content->days, $visibility);
                }
                $section->content->sharedContentDefinitions = $definitions;
            }
        }
        $tree->sharedContentDefinitions = [];

        return $tree;
    }

    private function visibleElements(array $elements): array
    {
        $visible = [];
        foreach ($elements as $element) {
            if ($element->isHidden ?? false) {
                continue;
            }
            if ($element->type === 'compositionGroup') {
                $element->children = $this->visibleElements($element->children);
            }
            if ($element->type === 'schedule' && isset($element->days)) {
                $element->days = $this->visibleDays($element->days, (array) ($element->appearance ?? new stdClass));
            }
            $visible[] = $element;
        }

        return $visible;
    }

    private function visibleDays(array $days, array $appearance = []): array
    {
        $result = [];
        foreach ($days as $day) {
            if (! $day->visible) {
                continue;
            }
            $copy = clone $day;
            $copy->activities = array_values(array_filter($day->activities, fn (object $activity): bool => $activity->visible && ScheduleContent::hasTitle($activity->title)));
            foreach ($copy->activities as $index => $activity) {
                $copy->activities[$index] = clone $activity;
                foreach (['showDescriptions' => 'description', 'showLocations' => 'location', 'showMapLinks' => 'mapUrl'] as $toggle => $field) {
                    if (($appearance[$toggle] ?? true) === false) {
                        $copy->activities[$index]->{$field} = null;
                    }
                }
            }
            if ($copy->activities !== []) {
                $result[] = $copy;
            }
        }

        return $result;
    }

    public function mediaIds(stdClass $presentation): array
    {
        $ids = [];
        foreach ($presentation->website->sections as $section) {
            $data = json_decode(json_encode($section, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            foreach ($this->references->extract($data['type'], $data['content'], $data['appearance']) as $reference) {
                $ids[] = $reference['assetId'];
            }
        }

        return array_values(array_unique($ids));
    }

    /** Delivery URLs are request-time data, never persisted in the snapshot. */
    public function website(stdClass $presentation, Event $event, ?string $token = null): stdClass
    {
        $publicIds = $token === null ? [] : $this->mediaIds($this->publicAudience($presentation));
        $media = [];
        foreach (MediaAsset::query()->where('event_id', $event->id)->whereKey($this->mediaIds($presentation))->with('variants')->get() as $asset) {
            $web = $asset->variants->firstWhere('variant_key', 'web');
            if ($web === null) {
                continue;
            }
            $url = $token !== null && ! in_array($asset->id, $publicIds, true)
                ? route('private-invitations.media.web', ['token' => $token, 'asset' => $asset->id])
                : route('public.events.media.web', ['slug' => $event->slug, 'asset' => $asset->id]);
            $media[$asset->id] = ['id' => $asset->id, 'originalFilename' => $asset->original_filename,
                'width' => $asset->width, 'height' => $asset->height,
                'web' => ['width' => $web->width, 'height' => $web->height, 'url' => $url]];
        }
        $presentation->website->media = (object) $media;

        return $presentation->website;
    }

    private function publicAudience(stdClass $presentation): stdClass
    {
        $copy = clone $presentation;
        $copy->website = clone $presentation->website;
        $copy->website->sections = array_values(array_filter($copy->website->sections, fn (stdClass $section): bool => $section->type !== 'rsvp'));

        return $copy;
    }
}
