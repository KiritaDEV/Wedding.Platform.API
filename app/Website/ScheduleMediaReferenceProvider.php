<?php

namespace App\Website;

final class ScheduleMediaReferenceProvider implements WebsiteMediaReferenceProvider
{
    public function section(string $type, array $content, array $appearance): array
    {
        return [];
    }

    public function element(array $element, string $compositionScope): array
    {
        if ($element['type'] !== 'schedule') {
            return [];
        }
        $references = [];
        foreach ($element['days'] as $day) {
            foreach ($day['activities'] as $activity) {
                if ($activity['imageMediaId'] !== null) {
                    $references[] = ['mediaId' => $activity['imageMediaId'], 'reference' => [
                        'type' => 'sectionMedia', 'compositionScope' => $compositionScope, 'elementId' => $element['id'],
                        'dayId' => $day['id'], 'activityId' => $activity['id'],
                    ]];
                }
            }
        }

        return $references;
    }
}
