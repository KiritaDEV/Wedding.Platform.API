<?php

namespace App\Website\Publishing;

use App\Enums\EventType;
use App\Http\Resources\WebsiteSectionResource;
use App\Http\Resources\WebsiteTemplateCapabilitiesResource;
use App\Models\Event;
use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\Elements\GoogleMaps;
use App\Website\Elements\ScheduleAppearance;
use App\Website\Elements\Swatches;
use App\Website\ScheduleContent;
use App\Website\WebsiteSchema;
use App\Website\WebsiteSectionMediaReferences;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/** Validates stored presentation without consulting mutable draft records or media delivery. */
final class PublishedWebsiteSnapshotReader
{
    public function __construct(
        private readonly PublishedWebsiteDraftValidator $validator,
        private readonly WebsiteSectionMediaReferences $media,
        private readonly WebsiteTemplateRegistry $templates,
        private readonly WebsiteCapabilityResolver $capabilities,
    ) {}

    public function decode(string $json): PublishedWebsiteSnapshot
    {
        try {
            $tree = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            if (! is_object($tree)) {
                throw new InvalidPublishedWebsiteSnapshot('Snapshot must be a JSON object.');
            }
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            Validator::make($data, [
                'project' => ['required', 'array:id,eventId,name'],
                'project.id' => ['required', 'ulid'], 'project.eventId' => ['required', 'ulid'],
                'project.name' => ['required', 'string', 'max:100'],
                'event' => ['required', 'array:id,name,slug,type,eventDate,startTime,timeZone,startsAtUtc'],
                'event.id' => ['required', 'ulid'], 'event.name' => ['required', 'string'], 'event.slug' => ['required', 'string'],
                'event.type' => ['required', Rule::enum(EventType::class)],
                'event.eventDate' => ['present', 'nullable', 'date_format:Y-m-d'],
                'event.startTime' => ['present', 'nullable', 'date_format:H:i'],
                'event.timeZone' => ['required', 'timezone:all'],
                'event.startsAtUtc' => ['present', 'nullable', 'date_format:Y-m-d\TH:i:s\Z'],
                'website' => ['required', 'array:schemaVersion,id,templateKey,designSettings,projectDesignDefaults,template,sections'],
                'website.schemaVersion' => ['required', 'integer', Rule::in([WebsiteSchema::CURRENT_SCHEMA_VERSION])],
                'website.id' => ['required', 'ulid'], 'website.templateKey' => ['required', 'string'],
                'website.designSettings' => ['required', 'array'],
                'website.projectDesignDefaults' => ['required', 'array:headingFontId,bodyFontId,headingColorId,bodyColorId,accentColorId'],
                'website.projectDesignDefaults.*' => ['required', 'string'],
                'website.template' => ['required', 'array:key,displayName,designOptions,capabilities'],
                'website.template.key' => ['required', 'string'], 'website.template.displayName' => ['required', 'string'],
                'website.template.designOptions' => ['required', 'array'], 'website.template.capabilities' => ['required', 'array'],
                'website.template.designOptions.colorThemes' => ['required', 'list', 'min:1'],
                'website.template.designOptions.fontSets' => ['required', 'list', 'min:1'],
                'website.template.designOptions.artStyles' => ['required', 'list', 'min:1'],
                'website.sections' => ['present', 'list'],
                'website.sections.*' => ['array:id,type,displayName,editorName,sortOrder,isEnabled,content,appearance,designDefaults,resolvedDesignContext,appearanceOptions,mediaCapability,itemMediaCapability,presentationCapability'],
                'website.sections.*.id' => ['required', 'ulid', 'distinct:strict'],
                'website.sections.*.type' => ['required', 'string'], 'website.sections.*.displayName' => ['required', 'string'],
                'website.sections.*.editorName' => ['present', 'nullable', 'string', 'max:80'],
                'website.sections.*.sortOrder' => ['required', 'integer'], 'website.sections.*.isEnabled' => ['present', 'boolean'],
                'website.sections.*.content' => ['required', 'array'], 'website.sections.*.appearance' => ['present', 'array'],
                'website.sections.*.designDefaults' => ['present', 'array:headingFontId,bodyFontId,headingColorId,bodyColorId,accentColorId'],
                'website.sections.*.designDefaults.*' => ['required', 'string'],
                'mediaAssetIds' => ['present', 'list'], 'mediaAssetIds.*' => ['required', 'ulid', 'distinct:strict'],
                'sharedContentDefinitions' => ['present', 'array', 'list'],
                'sharedContentDefinitions.*' => ['required', 'array:sectionId,id,type,content'],
                'sharedContentDefinitions.*.sectionId' => ['required', 'ulid'],
                'sharedContentDefinitions.*.id' => ['required', 'string'],
                'sharedContentDefinitions.*.type' => ['required', 'in:schedule'],
                'sharedContentDefinitions.*.content' => ['required', 'array:days'],
            ])->validate();
            foreach (['project', 'event', 'website'] as $key) {
                if (! is_object($tree->{$key})) {
                    throw new InvalidPublishedWebsiteSnapshot('Snapshot records must be JSON objects.');
                }
            }
            if (! is_array($tree->mediaAssetIds) || ! is_array($tree->sharedContentDefinitions) || ! is_array($tree->website->sections)
                || ! is_int($tree->website->schemaVersion)) {
                throw new InvalidPublishedWebsiteSnapshot('Invalid snapshot list or schema contract.');
            }
            if (array_diff(array_keys($data), ['project', 'event', 'website', 'mediaAssetIds', 'sharedContentDefinitions']) !== []) {
                throw new InvalidPublishedWebsiteSnapshot('Unexpected snapshot fields.');
            }
            if ($data['project']['id'] !== $data['website']['id'] || $data['project']['eventId'] !== $data['event']['id']
                || $data['website']['templateKey'] !== $data['website']['template']['key']) {
                throw new InvalidPublishedWebsiteSnapshot('Snapshot identities do not match.');
            }
            $event = (new Event)->forceFill([
                'type' => $data['event']['type'], 'event_date' => $data['event']['eventDate'],
                'start_time' => $data['event']['startTime'], 'time_zone' => $data['event']['timeZone'],
            ]);
            if ($event->startsAtUtc()?->format('Y-m-d\TH:i:s\Z') !== $data['event']['startsAtUtc']) {
                throw new InvalidPublishedWebsiteSnapshot('Inconsistent frozen Event timing.');
            }
            $website = (new Website)->forceFill([
                'id' => $data['project']['id'], 'event_id' => $data['event']['id'],
                'template_key' => $data['website']['templateKey'], 'schema_version' => $data['website']['schemaVersion'],
                'design_settings' => $data['website']['designSettings'],
            ])->setRelation('event', $event);
            $sections = collect();
            $assetIds = [];
            $previous = null;
            foreach ($tree->sharedContentDefinitions as $definition) {
                if (! is_object($definition) || ! is_object($definition->content) || ! ScheduleContent::validJsonDays($definition->content->days ?? null)) {
                    throw new InvalidPublishedWebsiteSnapshot('Shared definitions require object content and an entry list.');
                }
            }
            $assertScheduleShape = function (mixed $value) use (&$assertScheduleShape): void {
                if (is_array($value)) {
                    foreach ($value as $child) {
                        $assertScheduleShape($child);
                    }
                } elseif (is_object($value)) {
                    if (($value->type ?? null) === 'swatches' && property_exists($value, 'editorName') && ! Swatches::validJson($value)) {
                        throw new InvalidPublishedWebsiteSnapshot('Swatches requires object settings and a list of item objects.');
                    }
                    if (($value->type ?? null) === 'map' && property_exists($value, 'editorName') && property_exists($value, 'appearance') && ! GoogleMaps::validJsonAppearance($value->appearance)) {
                        throw new InvalidPublishedWebsiteSnapshot('Map appearance requires JSON objects.');
                    }
                    if (($value->type ?? null) === 'schedule' && property_exists($value, 'editorName') && property_exists($value, 'appearance') && ! ScheduleAppearance::validJson($value->appearance)) {
                        throw new InvalidPublishedWebsiteSnapshot('Schedule appearance requires JSON objects.');
                    }
                    if (($value->type ?? null) === 'schedule' && ! isset($value->contentId) && isset($value->days) && ! ScheduleContent::validJsonDays($value->days)) {
                        throw new InvalidPublishedWebsiteSnapshot('Days and Activities require JSON lists of objects.');
                    }
                    foreach (get_object_vars($value) as $child) {
                        $assertScheduleShape($child);
                    }
                }
            };
            $assertScheduleShape($tree->website);
            $definitions = [];
            $sectionIds = array_column($data['website']['sections'], 'id');
            foreach ($data['sharedContentDefinitions'] as $definition) {
                if (! in_array($definition['sectionId'], $sectionIds, true)) {
                    throw new InvalidPublishedWebsiteSnapshot('Unknown shared content owner.');
                }
                $owner = $definition['sectionId'];
                unset($definition['sectionId']);
                $definitions[$owner][] = $definition;
            }
            foreach ($data['website']['sections'] as $item) {
                if (array_key_exists('sharedContentDefinitions', $item['content'])) {
                    throw new InvalidPublishedWebsiteSnapshot('Definitions must be captured once in the snapshot collection.');
                }
                if (isset($definitions[$item['id']])) {
                    $item['content']['sharedContentDefinitions'] = $definitions[$item['id']];
                }
                $sectionTree = $tree->website->sections[count($sections)];
                if (! is_object($sectionTree) || ! is_object($sectionTree->content) || ! is_object($sectionTree->appearance) || ! is_object($sectionTree->designDefaults)) {
                    throw new InvalidPublishedWebsiteSnapshot('Invalid Section object contract.');
                }
                if (! is_bool($item['isEnabled']) || ! is_int($item['sortOrder']) || ($previous !== null && [$previous['sortOrder'], $previous['id']] > [$item['sortOrder'], $item['id']])) {
                    throw new InvalidPublishedWebsiteSnapshot('Invalid Section state or ordering.');
                }
                $previous = $item;
                $appearance = $item['appearance'];
                if ($item['designDefaults'] !== []) {
                    $appearance['designDefaults'] = $item['designDefaults'];
                }
                $section = (new WebsiteSection)->forceFill([
                    'id' => $item['id'], 'type' => $item['type'], 'appearance' => $appearance,
                    'content' => $item['content'], 'editor_name' => $item['editorName'],
                ])->setRelation('website', $website);
                $sections->push($section);
                foreach ($this->media->extract($item['type'], $item['content'], $item['appearance']) as $reference) {
                    $assetIds[] = $reference['assetId'];
                }
            }
            $website->setRelation('sections', $sections);
            $this->validator->validate($website, validateMedia: false);
            $assetIds = array_values(array_unique($assetIds));
            sort($assetIds, SORT_STRING);
            if ($assetIds !== $data['mediaAssetIds']) {
                throw new InvalidPublishedWebsiteSnapshot('Invalid media identity manifest.');
            }
            // Validate presentation descriptor structure, not catalog values: frozen
            // labels/colors/fonts/defaults must not be replaced with today's values.
            $template = $this->templates->get($website->template_key);
            $expectedTemplate = [
                'key' => $template->key, 'displayName' => $template->displayName, 'designOptions' => $template->designOptions,
                'capabilities' => (new WebsiteTemplateCapabilitiesResource($this->capabilities->template($template)))->presentation(),
            ];
            $this->assertDescriptorShape($tree->website->template, json_decode(json_encode($expectedTemplate, JSON_THROW_ON_ERROR), false));
            $this->assertDescriptorShape($tree->website->projectDesignDefaults, (object) array_fill_keys(['headingFontId', 'bodyFontId', 'headingColorId', 'bodyColorId', 'accentColorId'], ''));
            foreach ($sections as $index => $section) {
                $expected = (new WebsiteSectionResource($section, $section->content))->presentation();
                foreach (['resolvedDesignContext', 'appearanceOptions', 'mediaCapability', 'itemMediaCapability', 'presentationCapability'] as $key) {
                    if (! array_key_exists($key, $data['website']['sections'][$index])) {
                        throw new InvalidPublishedWebsiteSnapshot('Missing Section presentation metadata.');
                    }
                    $this->assertDescriptorShape($tree->website->sections[$index]->{$key}, json_decode(json_encode($expected[$key], JSON_THROW_ON_ERROR), false));
                }
            }

            return new PublishedWebsiteSnapshot($data['project'], $data['event'], get_object_vars($tree->website), $assetIds, $data['sharedContentDefinitions']);
        } catch (InvalidPublishedWebsiteSnapshot $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InvalidPublishedWebsiteSnapshot('Malformed published snapshot.', previous: $exception);
        }
    }

    private function assertDescriptorShape(mixed $value, mixed $prototype): void
    {
        if ((is_int($value) || is_float($value)) && (is_int($prototype) || is_float($prototype))) {
            return;
        }
        if (get_debug_type($value) !== get_debug_type($prototype)) {
            throw new InvalidPublishedWebsiteSnapshot('Invalid presentation descriptor type.');
        }
        if (is_object($prototype)) {
            $keys = array_keys(get_object_vars($value));
            $expected = array_keys(get_object_vars($prototype));
            sort($keys);
            sort($expected);
            if ($keys !== $expected) {
                throw new InvalidPublishedWebsiteSnapshot('Invalid presentation descriptor fields.');
            }
            foreach ($expected as $key) {
                $this->assertDescriptorShape($value->{$key}, $prototype->{$key});
            }
        } elseif (is_array($prototype)) {
            foreach ($value as $item) {
                $matches = false;
                foreach ($prototype as $example) {
                    try {
                        $this->assertDescriptorShape($item, $example);
                        $matches = true;
                        break;
                    } catch (InvalidPublishedWebsiteSnapshot) {
                        // Descriptor lists can contain multiple current control shapes.
                    }
                }
                if (! $matches) {
                    throw new InvalidPublishedWebsiteSnapshot('Invalid presentation descriptor list.');
                }
            }
        }
    }
}
