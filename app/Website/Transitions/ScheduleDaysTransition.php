<?php

namespace App\Website\Transitions;

use App\Website\ScheduleContent;
use Illuminate\Support\Facades\Validator;

/** Explicit one-time conversion only. Never called by draft or snapshot readers. */
final class ScheduleDaysTransition
{
    public function convert(array $flat, string $dayId): array
    {
        Validator::make(['content' => $flat], [
            'content' => ['required', 'array:items'], 'content.items' => ['present', 'array', 'list', 'max:100'],
            'content.items.*' => ['required', 'array:id,time,title,details'],
            'content.items.*.id' => ['required', 'string', 'max:255', 'regex:/^\S+$/u'],
            'content.items.*.time' => ['present', 'string'], 'content.items.*.title' => ['present', 'string'],
            'content.items.*.details' => ['present', 'string'],
        ])->validate();
        $activities = array_map(fn (array $item): array => [
            'id' => $item['id'], 'title' => $item['title'] === '' ? null : $item['title'],
            'startTime' => $item['time'] === '' ? null : $item['time'], 'endTime' => null,
            'description' => $item['details'] === '' ? null : $item['details'],
            'location' => null, 'mapUrl' => null, 'imageMediaId' => null, 'visible' => true,
        ], $flat['items']);

        return app(ScheduleContent::class)->validate(['days' => [[
            'id' => $dayId, 'date' => null, 'title' => null, 'visible' => true, 'activities' => $activities,
        ]]]);
    }
}
