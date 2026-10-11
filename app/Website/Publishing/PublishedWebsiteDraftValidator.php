<?php

namespace App\Website\Publishing;

use App\Actions\Websites\UpdateWebsiteSectionAppearance;
use App\Models\Website;
use App\Website\Capabilities\GlobalDesignControlId;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\ProjectColorLibrary;
use App\Website\WebsiteSchema;
use App\Website\WebsiteSectionContentValidator;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PublishedWebsiteDraftValidator
{
    public function __construct(
        private readonly WebsiteTemplateRegistry $templates,
        private readonly WebsiteCapabilityResolver $capabilities,
        private readonly WebsiteSectionContentValidator $content,
        private readonly UpdateWebsiteSectionAppearance $appearance,
    ) {}

    public function validate(Website $website, bool $validateMedia = true): void
    {
        $template = $this->templates->get($website->template_key);
        if ($website->schema_version !== WebsiteSchema::CURRENT_SCHEMA_VERSION || $template === null || ! $template->supportsEventType($website->event->type)) {
            throw ValidationException::withMessages(['snapshot' => 'Snapshot projection requires a current Website contract and a known Template.']);
        }

        $defaultRules = ['sometimes', 'array:headingFontId,bodyFontId,headingColorId,bodyColorId,accentColorId'];
        Validator::make(['design' => $website->design_settings], [
            'design' => ['required', 'array:colorTheme,fontSet,artStyle,projectDefaults,customColors'],
            'design.colorTheme' => ['required', 'string'],
            'design.fontSet' => ['required', 'string'],
            'design.artStyle' => ['required', 'string'],
            'design.projectDefaults' => ['present', ...$defaultRules],
            'design.projectDefaults.*' => ['required', 'string'],
            'design.customColors' => ['present', 'array'],
            'design.customColors.*' => ['array:id,value'],
            'design.customColors.*.id' => ['required', 'string'],
            'design.customColors.*.value' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ])->validate();
        foreach (GlobalDesignControlId::cases() as $control) {
            if (! $this->capabilities->allowsGlobalDesignValue($template, $control, $website->design_settings[$control->value])) {
                throw ValidationException::withMessages(['snapshot.designSettings' => 'Invalid Template design setting.']);
            }
        }
        if ($this->capabilities->normalizeProjectDefaultOverrides($template, $website->design_settings['projectDefaults']) !== $website->design_settings['projectDefaults']
            || count((new ProjectColorLibrary)->normalize($website->design_settings['customColors'])) !== count($website->design_settings['customColors'])) {
            throw ValidationException::withMessages(['snapshot.designSettings' => 'Invalid Project design defaults or color library.']);
        }

        // Validate before normalization: never invoke the legacy RSVP fallback.
        foreach ($website->sections as $section) {
            $section->setRelation('website', $website);
            $capability = $this->capabilities->section($template, $section->type);
            if ($capability === null) {
                throw ValidationException::withMessages(['snapshot.sections' => 'Section is unsupported by the assigned Template.']);
            }
            try {
                $this->content->validate($section->type, $section->content, $capability->allowedElementTypes, templateKey: $template->key);
                Validator::make(['appearance' => $section->appearance], [
                    'appearance.designDefaults' => $defaultRules,
                    'appearance.designDefaults.*' => ['required', 'string'],
                ])->validate();
                // The existing appearance validator supports non-persisting validation.
                // It may normalize the clone, but cannot change the saved draft.
                $this->appearance->handle(clone $section, $section->appearance, persist: false, validateMedia: $validateMedia);
            } catch (ValidationException $exception) {
                $errors = [];
                foreach ($exception->errors() as $path => $messages) {
                    $errors['sections.'.$section->id.'.'.$path] = $messages;
                }
                throw ValidationException::withMessages($errors);
            }
        }

    }
}
