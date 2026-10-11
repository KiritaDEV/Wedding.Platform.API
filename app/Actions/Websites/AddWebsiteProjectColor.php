<?php

namespace App\Actions\Websites;

use App\Models\Website;
use App\Website\ProjectColorLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AddWebsiteProjectColor
{
    public function __construct(private readonly ProjectColorLibrary $colors) {}

    public function handle(Website $website, string $value): Website
    {
        return DB::transaction(function () use ($website, $value): Website {
            $locked = Website::query()->lockForUpdate()->findOrFail($website->getKey());
            $settings = is_array($locked->design_settings) ? $locked->design_settings : [];
            $colors = $this->colors->normalize($settings['customColors'] ?? []);
            $normalized = $this->colors->normalizeValue($value);
            if ($normalized === null) {
                throw ValidationException::withMessages(['value' => 'Project colors must use #RRGGBB format.']);
            }
            if (count($colors) >= ProjectColorLibrary::MAXIMUM) {
                throw ValidationException::withMessages(['value' => 'A Website Project may contain at most 32 custom colors.']);
            }
            if (collect($colors)->contains('value', $normalized)) {
                throw ValidationException::withMessages(['value' => 'This color already exists in the Website Project.']);
            }

            $colors[] = [
                'id' => ProjectColorLibrary::ID_PREFIX.(string) Str::ulid(),
                'value' => $normalized,
            ];
            $settings['customColors'] = $colors;
            $locked->design_settings = $settings;
            $locked->save();

            return $locked;
        });
    }
}
