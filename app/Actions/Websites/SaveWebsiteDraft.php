<?php

namespace App\Actions\Websites;

use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Website;
use App\Website\Publishing\WebsitePublicationCoordinator;
use App\Website\WebsiteSectionMediaReferences;
use Illuminate\Validation\ValidationException;

/** PATCH: omitted sections/fields are retained; submitted content/appearance are complete envelopes. */
final class SaveWebsiteDraft
{
    public function handle(Website $website, array $changes): Website
    {
        return app(WebsitePublicationCoordinator::class)->forDraftMutation($website, function (Event $event, Website $project) use ($changes): Website {
            $sections = $project->sections->keyBy('id');
            foreach ($changes['sections'] ?? [] as $index => $change) {
                if (! $sections->has($change['id'])) {
                    throw ValidationException::withMessages(["sections.{$index}.id" => 'This Section is missing or belongs to another Website Project.']);
                }
            }
            if (array_key_exists('sectionIds', $changes)) {
                app(ReorderWebsiteSections::class)->handle($project, $changes['sectionIds']);
            }
            foreach ($changes['sections'] ?? [] as $index => $change) {
                $section = $sections[$change['id']];
                try {
                    if (array_key_exists('content', $change)) {
                        $compositionAppearance = in_array($section->type, ['hero', 'gallery', 'blank'], true);
                        $retainedAppearance = $section->appearance;
                        app(UpdateWebsiteSectionContent::class)->handle($section, $change['content'], ! $compositionAppearance);
                        if ($compositionAppearance && ! array_key_exists('appearance', $change)) {
                            // The appearance validator also supports animation-only device branches.
                            app(UpdateWebsiteSectionAppearance::class)->handle($section, $retainedAppearance);
                        }
                    }
                    if (array_key_exists('appearance', $change)) {
                        app(UpdateWebsiteSectionAppearance::class)->handle($section, $change['appearance']);
                    }
                    if (array_key_exists('isEnabled', $change)) {
                        app(SetWebsiteSectionEnabled::class)->handle($section, $change['isEnabled']);
                    }
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $field) => ["sections.{$index}.{$field}" => $messages])->all());
                }
            }
            $references = app(WebsiteSectionMediaReferences::class);
            $assetIds = collect($changes['sections'] ?? [])->flatMap(function (array $change) use ($sections, $references): array {
                $section = $sections[$change['id']];

                return $references->extract($section->type, $section->content, $section->appearance);
            })->pluck('assetId')->unique()->sort()->values();
            if ($assetIds->isNotEmpty()) {
                MediaAsset::query()->where('event_id', $event->id)->whereKey($assetIds)->orderBy('id')->lockForUpdate()->get();
                MediaVariant::query()->whereIn('media_asset_id', $assetIds)->orderBy('id')->lockForUpdate()->get();
            }
            if (array_key_exists('designSettings', $changes)) {
                app(UpdateWebsiteDesignSettings::class)->handle($project, $changes['designSettings']);
            }

            return $project;
        });
    }
}
