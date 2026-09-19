<?php

namespace Tests\Unit;

use App\Website\Capabilities\ContextDefaultsIntent;
use App\Website\Capabilities\DesignContextResolver;
use App\Website\Capabilities\ResolvedDesignContext;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\WebsiteTemplateRegistry;
use InvalidArgumentException;
use Tests\TestCase;

class DesignContextResolverTest extends TestCase
{
    public function test_section_context_rejects_unsupported_roles_and_values(): void
    {
        $capabilities = app(WebsiteCapabilityResolver::class);
        $resolver = app(DesignContextResolver::class);
        $template = WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1;
        $project = ResolvedDesignContext::fromProjectDefaults($capabilities->resolveProjectDesignDefaults($template, []));
        $gallery = $capabilities->section($template, 'gallery');
        $this->assertSame([], $gallery->contextDefaults->typography);
        $this->assertSame([], $gallery->contextDefaults->colors);

        foreach ([
            new ContextDefaultsIntent(bodyFontId: 'classic-serif'),
            new ContextDefaultsIntent(headingFontId: 'classic-serif'),
            new ContextDefaultsIntent(headingColorId: 'missing-color'),
        ] as $intent) {
            try {
                $resolver->resolveSection($project, $gallery, $intent);
                $this->fail('Invalid contextual intent should be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertEquals($project, $project);
            }
        }
    }

    public function test_block_context_and_element_bridge_apply_only_local_legal_overrides(): void
    {
        $capabilities = app(WebsiteCapabilityResolver::class);
        $resolver = app(DesignContextResolver::class);
        $template = WebsiteTemplateRegistry::MODERN_EDITORIAL_V1;
        $project = ResolvedDesignContext::fromProjectDefaults($capabilities->resolveProjectDesignDefaults($template, []));
        $blockCapability = $capabilities->blockContextDefaults($template);
        $block = $resolver->resolveBlock(
            $project,
            $blockCapability,
            new ContextDefaultsIntent(bodyColorId: 'plum-text'),
        );

        $this->assertSame('plum-text', $block->bodyColorId);
        $this->assertSame($project->headingColorId, $block->headingColorId);

        $elements = collect($capabilities->template($template)->elementCapabilities)->keyBy(fn ($element): string => $element->type->value);
        $this->assertEquals($block, $resolver->resolveElement($block, $elements['heading'], []));
        $heading = $resolver->resolveElement($block, $elements['heading'], [
            'headingFontId' => 'fashion-serif',
            'headingColorId' => 'plum-accent',
        ]);
        $this->assertSame('fashion-serif', $heading->headingFontId);
        $this->assertSame('plum-accent', $heading->headingColorId);
        $this->assertSame($block->bodyFontId, $heading->bodyFontId);
        $this->assertSame($block->bodyColorId, $resolver->resolveElement($block, $elements['text'], [])->bodyColorId);

        $this->expectException(InvalidArgumentException::class);
        $resolver->resolveElement($block, $elements['text'], ['headingFontId' => 'fashion-serif']);
    }
}
