<?php

namespace App\Website\Elements;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Sparse placement settings; spacing/animation are handled by their existing extractors. */
final class ScheduleAppearance
{
    public const TYPOGRAPHY_ROLES = ['dayHeading', 'activityTime', 'activityTitle', 'activityDescription', 'activityLocation', 'activityMapLink'];

    public static function validJson(mixed $appearance): bool
    {
        if (! $appearance instanceof \stdClass) {
            return false;
        }
        foreach (['timeline', 'list', 'cards', 'typography'] as $field) {
            if (property_exists($appearance, $field) && ! $appearance->{$field} instanceof \stdClass) {
                return false;
            }
        }
        if (isset($appearance->typography)) {
            foreach (get_object_vars($appearance->typography) as $role) {
                if (! $role instanceof \stdClass) {
                    return false;
                }
                if (property_exists($role, 'responsive')) {
                    if (! $role->responsive instanceof \stdClass) {
                        return false;
                    }
                    foreach (get_object_vars($role->responsive) as $branch) {
                        if (! $branch instanceof \stdClass) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    public static function extract(array &$element): ?array
    {
        if (! array_key_exists('appearance', $element)) {
            return null;
        }
        $appearance = $element['appearance'];
        if (! is_array($appearance)) {
            throw ValidationException::withMessages(['element.appearance' => 'Appearance must be an object.']);
        }
        $color = ['sometimes', 'required', 'string', 'max:255', 'regex:/^\S+$/u'];
        $spacing = ['sometimes', 'required', 'in:none,xs,s,m,l,xl'];
        $width = fn (array $allowed): array => ['sometimes', function ($attribute, $value, $fail) use ($allowed): void {
            if ((! is_int($value) && ! is_float($value)) || ! in_array($value, $allowed)) {
                $fail('Choose a supported integer width.');
            }
        }];
        Validator::make(['appearance' => $appearance], [
            'appearance' => ['present', 'array:layout,rowSpacing,timeline,list,cards,typography,dateFormat,timeFormat,showDescriptions,showLocations,showMapLinks,dayGap,contentWidth,contentAlignment'],
            'appearance.typography' => ['sometimes', 'array:'.implode(',', self::TYPOGRAPHY_ROLES)],
            'appearance.typography.*' => ['sometimes', 'array'],
            'appearance.dateFormat' => ['sometimes', 'required', 'in:long,short,weekday'],
            'appearance.timeFormat' => ['sometimes', 'required', 'in:12-hour,24-hour'],
            'appearance.showDescriptions' => ['sometimes', 'boolean'],
            'appearance.showLocations' => ['sometimes', 'boolean'],
            'appearance.showMapLinks' => ['sometimes', 'boolean'],
            'appearance.dayGap' => $spacing,
            'appearance.contentWidth' => ['sometimes', 'required', 'in:full,wide,medium,narrow'],
            'appearance.contentAlignment' => ['sometimes', 'required', 'in:start,center,end'],
            'appearance.layout' => ['sometimes', 'required', 'in:timeline,list,cards'], 'appearance.rowSpacing' => $spacing,
            'appearance.timeline' => ['sometimes', 'array:arrangement,marker,markerColorId,lineColorId,lineWidth'],
            'appearance.timeline.arrangement' => ['sometimes', 'required', 'in:left,alternating'],
            'appearance.timeline.marker' => ['sometimes', 'required', 'in:dot,number,minimal'],
            'appearance.timeline.markerColorId' => $color, 'appearance.timeline.lineColorId' => $color,
            'appearance.timeline.lineWidth' => $width([1, 2, 3]),
            'appearance.list' => ['sometimes', 'array:timePosition,separation'],
            'appearance.list.timePosition' => ['sometimes', 'required', 'in:left,above'], 'appearance.list.separation' => ['sometimes', 'required', 'in:none,line'],
            'appearance.cards' => ['sometimes', 'array:columns,gap,padding,backgroundColorId,borderColorId,borderWidth,radius,alignment'],
            'appearance.cards.columns' => ['sometimes', 'required', 'in:single,grid'],
            'appearance.cards.gap' => $spacing, 'appearance.cards.padding' => $spacing,
            'appearance.cards.backgroundColorId' => $color, 'appearance.cards.borderColorId' => $color,
            'appearance.cards.borderWidth' => $width([0, 1, 2, 3]),
            'appearance.cards.radius' => ['sometimes', 'required', 'in:none,s,m,l'], 'appearance.cards.alignment' => ['sometimes', 'required', 'in:start,center,end'],
        ])->validate();
        foreach (['showDescriptions', 'showLocations', 'showMapLinks'] as $key) {
            if (array_key_exists($key, $appearance) && ! is_bool($appearance[$key])) {
                throw ValidationException::withMessages(["element.appearance.{$key}" => 'Visibility must be a JSON boolean.']);
            }
        }
        foreach ($appearance['typography'] ?? [] as $role => $values) {
            try {
                app(WebsiteElementValidator::class)->validateScheduleTypography($values);
            } catch (ValidationException $exception) {
                $messages = [];
                foreach ($exception->errors() as $path => $errors) {
                    $messages[str_replace('element.appearance', "element.appearance.typography.{$role}", $path)] = $errors;
                }
                throw ValidationException::withMessages($messages);
            }
            if ($values === []) {
                unset($appearance['typography'][$role]);
            }
        }
        if (($appearance['typography'] ?? null) === []) {
            unset($appearance['typography']);
        }
        foreach (['timeline', 'list', 'cards'] as $key) {
            if (($appearance[$key] ?? null) === []) {
                unset($appearance[$key]);
            }
        }
        unset($element['appearance']);

        return $appearance === [] ? null : $appearance;
    }
}
