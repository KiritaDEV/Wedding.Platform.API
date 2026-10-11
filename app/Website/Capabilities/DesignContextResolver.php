<?php

namespace App\Website\Capabilities;

use InvalidArgumentException;

final class DesignContextResolver
{
    public function resolveSection(
        ResolvedDesignContext $parent,
        SectionCapability $section,
        ContextDefaultsIntent $intent,
        ?PresentationCapability $presentation = null,
    ): ResolvedDesignContext {
        $this->resolveContext($parent, $section->contextDefaults, $intent);
        $effectiveCapability = $presentation?->contextDefaults ?? $section->contextDefaults;
        $allowedKeys = array_keys($this->allowedContextValues($effectiveCapability));
        $effectiveIntent = array_intersect_key($intent->toArray(), array_flip($allowedKeys));

        return $this->resolveContext($parent, $effectiveCapability, ContextDefaultsIntent::fromArray($effectiveIntent));
    }

    public function resolveBlock(
        ResolvedDesignContext $parent,
        ContextDefaultsCapability $capability,
        ContextDefaultsIntent $intent,
    ): ResolvedDesignContext {
        return $this->resolveContext($parent, $capability, $intent);
    }

    /** @param array<string, mixed> $intent */
    public function resolveElement(
        ResolvedDesignContext $parent,
        ElementCapability $element,
        array $intent,
    ): ResolvedDesignContext {
        $allowed = [];
        foreach ($element->appearance?->typography ?? [] as $control) {
            $allowed[$control->role === TypographyRole::Heading ? 'headingFontId' : 'bodyFontId'] = $control->allowedFontIds;
        }
        foreach ($element->appearance?->colors ?? [] as $control) {
            $key = match ($control->role) {
                ElementColorRole::HeadingColor => 'headingColorId',
                ElementColorRole::TextColor => 'textColorId',
                ElementColorRole::AccentColor => 'accentColorId',
            };
            $allowed[$key] = $control->allowedColorIds;
        }

        foreach ($intent as $key => $value) {
            if (! isset($allowed[$key])) {
                throw new InvalidArgumentException("Element Appearance role [{$key}] is not supported here.");
            }
            if (! is_string($value) || ! in_array($value, $allowed[$key], true)) {
                throw new InvalidArgumentException("Element Appearance value for [{$key}] is invalid.");
            }
        }

        $contextIntent = $intent;
        if (array_key_exists('textColorId', $contextIntent)) {
            $contextIntent['bodyColorId'] = $contextIntent['textColorId'];
            unset($contextIntent['textColorId']);
        }

        return $this->resolved($parent, $contextIntent);
    }

    private function resolveContext(
        ResolvedDesignContext $parent,
        ContextDefaultsCapability $capability,
        ContextDefaultsIntent $intent,
    ): ResolvedDesignContext {
        return $this->apply($parent, $this->allowedContextValues($capability), $intent->toArray());
    }

    /** @return array<string, list<string>> */
    public function allowedContextValues(ContextDefaultsCapability $capability): array
    {
        $allowed = [];
        foreach ($capability->typography as $control) {
            $allowed[$control->role === TypographyRole::Heading ? 'headingFontId' : 'bodyFontId'] = $control->allowedFontIds;
        }
        foreach ($capability->colors as $control) {
            $key = match ($control->role) {
                ContainerColorRole::HeadingColor => 'headingColorId',
                ContainerColorRole::BodyColor => 'bodyColorId',
                ContainerColorRole::AccentColor => 'accentColorId',
            };
            $allowed[$key] = $control->allowedColorIds;
        }

        return $allowed;
    }

    /**
     * @param  array<string, list<string>>  $allowed
     * @param  array<string, mixed>  $intent
     */
    private function apply(ResolvedDesignContext $parent, array $allowed, array $intent): ResolvedDesignContext
    {
        foreach ($intent as $key => $value) {
            if (! isset($allowed[$key])) {
                throw new InvalidArgumentException("Design Context role [{$key}] is not supported here.");
            }
            if (! is_string($value) || ! in_array($value, $allowed[$key], true)) {
                throw new InvalidArgumentException("Design Context value for [{$key}] is invalid.");
            }
        }

        return $this->resolved($parent, $intent);
    }

    /** @param array<string, mixed> $intent */
    private function resolved(ResolvedDesignContext $parent, array $intent): ResolvedDesignContext
    {
        return new ResolvedDesignContext(
            headingFontId: $intent['headingFontId'] ?? $parent->headingFontId,
            bodyFontId: $intent['bodyFontId'] ?? $parent->bodyFontId,
            headingColorId: $intent['headingColorId'] ?? $parent->headingColorId,
            bodyColorId: $intent['bodyColorId'] ?? $parent->bodyColorId,
            accentColorId: $intent['accentColorId'] ?? $parent->accentColorId,
        );
    }
}
