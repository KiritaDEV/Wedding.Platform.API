<?php

namespace App\Website\Publishing;

use App\Website\ScheduleContent;
use App\Website\SharedContent;

final class SchedulePublicationRule implements WebsitePublicationRule
{
    public function diagnostics(PublishedWebsiteSnapshot $snapshot): array
    {
        $data = json_decode($snapshot->toJson(), true, 512, JSON_THROW_ON_ERROR);
        $diagnostics = [];
        foreach ($data['website']['sections'] as $section) {
            if (! $section['isEnabled']) {
                continue;
            }
            $definitions = array_values(array_filter($data['sharedContentDefinitions'], fn (array $definition): bool => $definition['sectionId'] === $section['id']));
            $visit = function (array $elements) use (&$visit, &$diagnostics, $definitions, $section, $data): void {
                foreach ($elements as $element) {
                    if ($element['isHidden'] ?? false) {
                        continue;
                    }
                    if ($element['type'] === 'compositionGroup') {
                        $visit($element['children']);
                    }
                    if ($element['type'] !== 'schedule') {
                        continue;
                    }
                    $resolved = app(SharedContent::class)->resolve($element, $definitions);
                    foreach ($resolved['days'] as $day) {
                        if (! $day['visible']) {
                            continue;
                        }
                        foreach ($day['activities'] as $activity) {
                            if ($activity['visible'] && ! ScheduleContent::hasTitle($activity['title'])) {
                                $diagnostics[] = ['code' => 'schedule_activity_title_required', 'message' => 'Give this visible Activity a title or hide it before publishing.',
                                    'projectId' => $data['project']['id'], 'sectionId' => $section['id'], 'blockId' => $element['id'],
                                    'context' => ['dayId' => $day['id'], 'activityId' => $activity['id'], 'contentId' => $element['contentId'] ?? null, 'field' => 'title']];
                            }
                        }
                    }
                }
            };
            foreach ([$section['content']['compositions']['shared'], ...array_values($section['content']['compositions']['custom'] ?? [])] as $branch) {
                $visit($branch['childFlow']['elements']);
            }
        }

        return $diagnostics;
    }
}
