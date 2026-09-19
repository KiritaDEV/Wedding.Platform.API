<?php

namespace Tests\Unit;

use App\Enums\EventType;
use App\Website\WebsiteSectionRegistry;
use PHPUnit\Framework\TestCase;

class WebsiteSectionRegistryTest extends TestCase
{
    public function test_registry_exposes_only_the_final_canonical_inventory(): void
    {
        $registry = new WebsiteSectionRegistry;

        $this->assertSame(['hero', 'gallery', 'rsvp', 'blank'], array_keys($registry->all()));
        $this->assertSame(['hero', 'gallery', 'rsvp'], array_keys($registry->defaultCompositionFor(EventType::Wedding)));
        $this->assertNull($registry->get('people'));
        $this->assertNull($registry->get('story'));
    }

    public function test_registry_lookups_are_deliberate_and_content_is_semantic(): void
    {
        $registry = new WebsiteSectionRegistry;
        $presentationKeys = ['templateKey', 'componentName', 'cssClass', 'layout', 'font', 'background', 'color'];

        $this->assertNull($registry->get('unknown'));
        $this->assertFalse($registry->supports(EventType::Wedding, 'unknown'));
        $this->assertTrue($registry->supports(EventType::Wedding, 'hero'));

        foreach ($registry->all() as $definition) {
            $this->assertIsArray($definition->defaultContent);
            $this->assertSame([], array_intersect($presentationKeys, array_keys($definition->defaultContent)));
        }
    }

    public function test_functional_section_defaults_use_their_canonical_owners(): void
    {
        $registry = new WebsiteSectionRegistry;
        $this->assertSame(['semantic' => ['items' => []], 'compositions' => ['shared' => ['childFlow' => ['elements' => [], 'order' => [['kind' => 'specialized', 'key' => 'content']]]]]], $registry->get('gallery')->defaultContent);
        $this->assertSame(['semantic' => ['heading' => '', 'description' => '', 'buttonLabel' => '']], $registry->get('rsvp')->defaultContent);
        $this->assertArrayHasKey('compositions', $registry->get('gallery')->defaultContent);
        $this->assertArrayNotHasKey('compositions', $registry->get('rsvp')->defaultContent);
    }
}
