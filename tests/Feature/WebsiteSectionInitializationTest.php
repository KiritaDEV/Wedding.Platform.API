<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\InitializeWebsiteSections;
use App\Enums\EventMembershipRole;
use App\Enums\PlatformRole;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\WebsiteSectionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteSectionInitializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_wedding_website_receives_all_default_sections(): void
    {
        $website = Website::factory()->create();

        app(InitializeWebsiteSections::class)->handle($website);

        $sections = $website->sections()->get();
        $definitions = app(WebsiteSectionRegistry::class)->defaultCompositionFor($website->event->type);

        $this->assertSame(array_keys($definitions), $sections->pluck('type')->all());
        $this->assertSame([10, 70, 90], $sections->pluck('sort_order')->all());
        $this->assertSame(0, $sections->where('type', 'people')->count());
        $this->assertSame(0, $sections->where('type', 'faq')->count());
        $this->assertSame(0, $sections->where('type', 'date')->count());
        $this->assertSame(0, $sections->where('type', 'dressCode')->count());
        $this->assertSame(0, $sections->where('type', 'schedule')->count());
        $this->assertSame(0, $sections->where('type', 'venue')->count());
        $this->assertNotContains(false, $sections->pluck('is_enabled')->all(), true);

        foreach ($sections as $section) {
            if ($section->type === 'hero') {
                $this->assertCount(3, $section->content['compositions']['shared']['childFlow']['elements']);
                $this->assertSame(['text', 'date', 'text'], array_column($section->content['compositions']['shared']['childFlow']['elements'], 'type'));
                $this->assertSame(array_column($section->content['compositions']['shared']['childFlow']['elements'], 'id'), array_column($section->content['compositions']['shared']['childFlow']['order'], 'id'));

                continue;
            }
            $this->assertSame($definitions[$section->type]->defaultContent, $section->content);
        }
    }

    public function test_initialization_is_idempotent_and_preserves_existing_edits_and_unknown_sections(): void
    {
        $website = Website::factory()->create();
        $initializer = app(InitializeWebsiteSections::class);
        $initializer->handle($website);
        $hero = $website->sections()->where('type', 'hero')->sole();
        $hero->update([
            'content' => ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => ['elements' => [], 'order' => []]]]],
            'is_enabled' => false,
            'sort_order' => 7,
        ]);
        $legacy = WebsiteSection::factory()->for($website)->forType('customLegacySection')->create([
            'sort_order' => 15,
            'content' => ['body' => 'Keep me'],
        ]);

        $initializer->handle($website);

        $this->assertDatabaseCount('website_sections', 4);
        $this->assertSame(0, $website->sections()->where('type', 'faq')->count());
        $this->assertSame(['semantic' => [], 'compositions' => ['shared' => ['childFlow' => ['elements' => [], 'order' => []]]]], $hero->refresh()->content);
        $this->assertFalse($hero->is_enabled);
        $this->assertSame(7, $hero->sort_order);
        $this->assertSame(['body' => 'Keep me'], $legacy->refresh()->content);
    }

    public function test_initialization_adds_only_a_missing_canonical_section(): void
    {
        $website = Website::factory()->create();
        $initializer = app(InitializeWebsiteSections::class);
        $initializer->handle($website);
        $website->sections()->where('type', 'gallery')->delete();

        $initializer->handle($website);

        $this->assertSame(3, $website->sections()->count());
        $gallery = $website->sections()->where('type', 'gallery')->sole();
        $this->assertSame(70, $gallery->sort_order);
        $this->assertSame(app(WebsiteSectionRegistry::class)->get('gallery')->defaultContent, $gallery->content);
        $this->assertSame(0, $website->sections()->where('type', 'people')->count());
        $this->assertSame(0, $website->sections()->where('type', 'venue')->count());
    }

    public function test_explicit_initialization_keeps_product_roles_and_builds_the_complete_wedding_foundation(): void
    {
        $creator = User::factory()->create();

        $event = app(CreateEvent::class)->handle($creator, ['name' => 'A Wedding']);
        $this->initializeWebsite($event);

        $this->assertSame(PlatformRole::User, $creator->platform_role);
        $this->assertSame(EventMembershipRole::Owner, $event->memberships()->sole()->role);
        $this->assertSame(3, $event->website->sections()->count());
    }
}
