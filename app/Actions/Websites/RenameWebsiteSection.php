<?php

namespace App\Actions\Websites;

use App\Models\WebsiteSection;
use App\Website\WebsiteSectionEditorNames;
use App\Website\WebsiteSectionRegistry;
use Illuminate\Validation\ValidationException;

final class RenameWebsiteSection
{
    public function __construct(private readonly WebsiteSectionRegistry $sections, private readonly WebsiteSectionEditorNames $names) {}

    public function handle(WebsiteSection $section, mixed $editorName): WebsiteSection
    {
        if ($this->sections->get($section->type)?->lifecycle->isUserOwned() !== true) {
            throw ValidationException::withMessages(['editorName' => 'This Section cannot be renamed.']);
        }
        $section->editor_name = $this->names->normalize($editorName);
        $section->save();

        return $section;
    }
}
