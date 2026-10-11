<?php

namespace App\Http\Requests;

use App\Rules\IanaTimeZone;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateEventTimingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'eventDate' => ['present', 'nullable', 'date_format:Y-m-d'],
            'startTime' => ['present', 'nullable', 'date_format:H:i'],
            'timeZone' => ['required', 'string', new IanaTimeZone],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $date = $this->input('eventDate');
            $time = $this->input('startTime');
            $zone = $this->input('timeZone');
            if (! is_string($date) || ! is_string($time) || ! is_string($zone)) {
                return;
            }

            $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$time}", new DateTimeZone($zone));
            if ($local === false || $local->format('Y-m-d H:i') !== "{$date} {$time}") {
                $validator->errors()->add('startTime', 'The selected local time does not exist in this time zone.');
            }
        }];
    }

    /** @return array{event_date: ?string, start_time: ?string, time_zone: ?string} */
    public function timingAttributes(): array
    {
        $validated = $this->validated();

        return [
            'event_date' => $validated['eventDate'],
            'start_time' => $validated['startTime'],
            'time_zone' => $validated['timeZone'],
        ];
    }
}
