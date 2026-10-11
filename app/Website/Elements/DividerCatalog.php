<?php

namespace App\Website\Elements;

final class DividerCatalog
{
    /** @return list<string> */
    public static function assetIdsForTemplate(string $templateKey): array
    {
        return $templateKey === 'classic-filipiniana-v1' ? [
            'classic-divider-botanical-vine',
            'classic-divider-filigree-center',
            'classic-divider-floral-silhouette',
            'classic-divider-ornamental-flourish',
        ] : [];
    }
}
