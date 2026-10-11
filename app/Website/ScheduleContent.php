<?php

namespace App\Website;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Canonical days/activities contract. This class never accepts the flat model. */
final class ScheduleContent
{
    public const MAP_HOSTS = ['google.com', 'www.google.com', 'maps.google.com', 'maps.app.goo.gl', 'waze.com', 'www.waze.com', 'maps.apple.com'];

    public static function validJsonDays(mixed $days): bool
    {
        if (! is_array($days)) {
            return false;
        }
        foreach ($days as $day) {
            if (! $day instanceof \stdClass || ! is_array($day->activities ?? null)) {
                return false;
            }
            foreach ($day->activities as $activity) {
                if (! $activity instanceof \stdClass) {
                    return false;
                }
            }
        }

        return true;
    }

    public function validate(array $content): array
    {
        $id = ['required', 'string', 'max:255', 'regex:/^\S+$/u'];
        $text = fn (int $max): array => ['present', 'nullable', 'string', 'max:'.$max];
        $data = Validator::make(['content' => $content], [
            'content' => ['required', 'array:days'], 'content.days' => ['present', 'array', 'list', 'max:20'],
            'content.days.*' => ['required', 'array:id,date,title,visible,activities'],
            'content.days.*.id' => $id, 'content.days.*.date' => $text(10), 'content.days.*.title' => $text(255),
            'content.days.*.visible' => ['required', function ($attribute, $value, $fail): void {
                if (! is_bool($value)) {
                    $fail('Visibility must be a boolean.');
                }
            }],
            'content.days.*.activities' => ['present', 'array', 'list', 'max:100'],
            'content.days.*.activities.*' => ['required', 'array:id,title,startTime,endTime,description,location,mapUrl,imageMediaId,visible'],
            'content.days.*.activities.*.id' => $id,
            'content.days.*.activities.*.title' => $text(255),
            'content.days.*.activities.*.startTime' => $text(5), 'content.days.*.activities.*.endTime' => $text(5),
            'content.days.*.activities.*.description' => $text(5000), 'content.days.*.activities.*.location' => $text(255),
            'content.days.*.activities.*.mapUrl' => $text(2048),
            'content.days.*.activities.*.imageMediaId' => ['present', 'nullable', 'string', 'ulid'],
            'content.days.*.activities.*.visible' => ['required', function ($attribute, $value, $fail): void {
                if (! is_bool($value)) {
                    $fail('Visibility must be a boolean.');
                }
            }],
        ])->validate()['content'];
        $ids = [];
        $count = 0;
        foreach ($data['days'] as $di => &$day) {
            $path = "content.days.{$di}";
            $this->identity($day['id'], $ids, $path.'.id');
            foreach (['date', 'title'] as $field) {
                $day[$field] = $day[$field] === '' ? null : $day[$field];
            }
            if ($day['date'] !== null) {
                if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $day['date'], $parts) || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                    $this->fail($path.'.date', 'Use a valid YYYY-MM-DD calendar date.');
                }
            }
            foreach ($day['activities'] as $ai => &$activity) {
                $ap = $path.".activities.{$ai}";
                $this->identity($activity['id'], $ids, $ap.'.id');
                foreach (['title', 'startTime', 'endTime', 'description', 'location', 'mapUrl', 'imageMediaId'] as $field) {
                    $activity[$field] = $activity[$field] === '' ? null : $activity[$field];
                }
                foreach (['startTime', 'endTime'] as $field) {
                    if ($activity[$field] !== null && ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $activity[$field])) {
                        $this->fail($ap.'.'.$field, 'Use HH:mm.');
                    }
                }
                if ($activity['endTime'] !== null && ($activity['startTime'] === null || $activity['endTime'] <= $activity['startTime'])) {
                    $this->fail($ap.'.endTime', 'End time requires an earlier start time on the same day.');
                }
                if ($activity['mapUrl'] !== null && ! self::validMapUrl($activity['mapUrl'])) {
                    $this->fail($ap.'.mapUrl', 'Use an HTTPS Google Maps, Waze, or Apple Maps URL.');
                }
                if ($activity['description'] !== null) {
                    $activity['description'] = str_replace(["\r\n", "\r"], "\n", $activity['description']);
                }
                $count++;
            }
            unset($activity);
        }
        unset($day);
        if ($count > 100) {
            $this->fail('content.days', 'Schedule supports at most 100 Activities.');
        }

        return $data;
    }

    public static function validMapUrl(string $url): bool
    {
        if (! str_starts_with($url, 'https://') || preg_match('/[\s\\\\]/u', $url)) {
            return false;
        }
        $authority = preg_split('/[\/?#]/', substr($url, 8))[0];
        if (str_contains($authority, '@') || str_contains($authority, '%')) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === 443)
            && in_array(strtolower($parts['host'] ?? ''), self::MAP_HOSTS, true);
    }

    public static function hasTitle(?string $title): bool
    {
        return $title !== null && preg_match('/[^\\s\\x{FEFF}]/u', $title) === 1;
    }

    public function visibleDays(array $days): array
    {
        $result = [];
        foreach ($days as $day) {
            if (! $day['visible']) {
                continue;
            }
            $day['activities'] = array_values(array_filter($day['activities'], fn (array $activity): bool => $activity['visible'] && self::hasTitle($activity['title'])));
            if ($day['activities'] !== []) {
                $result[] = $day;
            }
        }

        return $result;
    }

    private function identity(string $id, array &$seen, string $path): void
    {
        if (isset($seen[$id])) {
            $this->fail($path, 'Day and Activity IDs must be unique within Schedule content.');
        }
        $seen[$id] = true;
    }

    private function fail(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => $message]);
    }
}
