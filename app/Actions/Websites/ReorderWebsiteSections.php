<?php

namespace App\Actions\Websites;

use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReorderWebsiteSections
{
    /** @param list<string> $sectionIds */
    public function handle(Website $website, array $sectionIds): void
    {
        $currentIds = $website->sections()->pluck('id')->all();

        if (count($sectionIds) !== count($currentIds)
            || array_values(array_unique($sectionIds)) !== $sectionIds
            || array_diff($sectionIds, $currentIds) !== []
            || array_diff($currentIds, $sectionIds) !== []) {
            throw ValidationException::withMessages([
                'sectionIds' => 'The section IDs must contain every Website Section exactly once.',
            ]);
        }

        DB::transaction(function () use ($website, $sectionIds): void {
            foreach ($sectionIds as $index => $sectionId) {
                $website->sections()->whereKey($sectionId)->update([
                    'sort_order' => ($index + 1) * 10,
                ]);
            }
        });
    }
}
