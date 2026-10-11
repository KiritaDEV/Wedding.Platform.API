<?php

namespace App\Website;

use App\Models\Website;
use Illuminate\Validation\ValidationException;

final class WebsiteSectionEditorNames
{
    public const MAX_LENGTH = 80;

    public function normalize(mixed $value): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages(['editorName' => 'The editor name must be a string.']);
        }
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($normalized === '' || mb_strlen($normalized) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['editorName' => 'The editor name must contain between 1 and 80 characters.']);
        }

        return $normalized;
    }

    public function allocate(Website $website, string $sectionType): string
    {
        $maximum = $website->sections()->where('type', $sectionType)->get(['editor_name'])
            ->reduce(function (int $maximum, $section): int {
                return preg_match('/^Section ([1-9]\d*)$/', (string) $section->editor_name, $matches) === 1
                    ? max($maximum, (int) $matches[1])
                    : $maximum;
            }, 0);

        return 'Section '.($maximum + 1);
    }
}
