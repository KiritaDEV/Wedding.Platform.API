<?php

namespace App\Website;

final class WebsiteSectionCompositions
{
    /**
     * @param  array<string, mixed>  $content
     * @return list<array{scope: string, composition: array<string, mixed>}>
     */
    public function persisted(array $content): array
    {
        $compositions = $content['compositions'] ?? null;
        if (! is_array($compositions) || ! is_array($compositions['shared'] ?? null)) {
            return [];
        }

        $branches = [['scope' => 'shared', 'composition' => $compositions['shared']]];
        foreach (['desktop', 'tablet', 'mobile'] as $viewport) {
            $composition = $compositions['custom'][$viewport] ?? null;
            if (is_array($composition)) {
                $branches[] = ['scope' => 'custom/'.$viewport, 'composition' => $composition];
            }
        }

        return $branches;
    }
}
