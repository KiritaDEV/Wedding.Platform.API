<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\InitializeEventWebsite;
use App\Actions\Websites\InitializeWebsiteSections;
use App\Enums\EventMembershipRole;
use App\Enums\EventType;
use App\Enums\PlatformRole;
use App\Exceptions\IncompatibleWebsiteTemplate;
use App\Exceptions\UnknownWebsiteTemplate;
use App\Models\Event;
use App\Models\User;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\WebsiteSectionRegistry;
use App\Website\WebsiteTemplateDefinition;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteInitializationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);
    }

    public function test_new_event_has_no_website_and_get_has_an_authorized_not_found_contract(): void
    {
        [$event, $owner] = $this->newEvent();
        $other = User::factory()->create();

        $this->assertNull($event->website);
        $this->assertDatabaseCount('websites', 0);
        $this->assertDatabaseCount('website_sections', 0);
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertNotFound();
        $this->actingAs($other)->getJson("/api/events/{$event->id}/website")->assertForbidden();
    }

    public function test_creation_templates_are_listed_before_initialization(): void
    {
        [$event, $owner] = $this->newEvent();

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website-templates")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.key', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1)
            ->assertJsonPath('data.1.key', WebsiteTemplateRegistry::MODERN_EDITORIAL_V1)
            ->assertJsonMissingPath('data.0.isSelected');
    }

    public function test_owner_explicitly_initializes_a_complete_website_with_target_defaults(): void
    {
        [$event, $owner] = $this->newEvent();
        $template = app(WebsiteTemplateRegistry::class)->get(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);

        $this->actingAs($owner)->postJson("/api/events/{$event->id}/website", [
            'templateKey' => $template->key,
        ])->assertCreated()
            ->assertJsonPath('data.templateKey', $template->key)
            ->assertJsonPath('data.designSettings', app(WebsiteCapabilityResolver::class)->canonicalDesignDefaults($template))
            ->assertJsonCount(3, 'data.sections');

        $website = $event->website()->sole();
        $this->assertSame($template->key, $website->template_key);
        $this->assertSame(array_keys(app(WebsiteSectionRegistry::class)->defaultCompositionFor($event->type)), $website->sections()->pluck('type')->all());
        foreach ($website->sections as $section) {
            $definition = app(WebsiteSectionRegistry::class)->get($section->type);
            if ($section->type === 'hero') {
                $this->assertSame(['text', 'date', 'text'], array_column($section->content['compositions']['shared']['childFlow']['elements'], 'type'));
                foreach (array_filter($section->content['compositions']['shared']['childFlow']['elements'], fn (array $element) => $element['type'] === 'text') as $element) {
                    $this->assertSame('doc', $element['document']['type']);
                }
            } else {
                $this->assertSame($definition->defaultContent, $section->content);
            }
            $expectedAppearance = in_array($section->type, ['hero', 'gallery', 'blank'], true)
                ? ['shared' => $template->appearanceDefaultsFor($section->type)]
                : $template->appearanceDefaultsFor($section->type);
            $this->assertSame($expectedAppearance, $section->appearance);
        }
    }

    public function test_initialization_requires_a_valid_explicit_template_and_event_access(): void
    {
        [$event, $owner] = $this->newEvent();
        $other = User::factory()->create();
        $url = "/api/events/{$event->id}/website";

        $this->postJson($url, ['templateKey' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1])->assertUnauthorized();
        $this->actingAs($other)->postJson($url, ['templateKey' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1])->assertForbidden();
        $this->actingAs($owner)->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('templateKey');
        $this->actingAs($owner)->postJson($url, ['templateKey' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors('templateKey');

        $superAdmin = User::factory()->create(['platform_role' => PlatformRole::SuperAdmin]);
        $this->actingAs($superAdmin)->postJson($url, ['templateKey' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1])->assertCreated();
    }

    public function test_an_event_member_can_initialize_through_the_existing_access_boundary(): void
    {
        [$event] = $this->newEvent();
        $admin = User::factory()->create();
        $event->memberships()->create(['user_id' => $admin->id, 'role' => EventMembershipRole::Admin]);

        $this->actingAs($admin)->postJson("/api/events/{$event->id}/website", [
            'templateKey' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
        ])->assertCreated();
    }

    public function test_domain_initialization_rejects_disabled_event_incompatible_and_section_incompatible_templates(): void
    {
        $production = app(WebsiteTemplateRegistry::class)->get(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);

        [$disabledEvent] = $this->newEvent();
        $disabled = $this->definitionFrom($production, key: 'disabled-test', enabled: false);
        $this->expectDomainFailure(UnknownWebsiteTemplate::class, $disabledEvent, $disabled);

        [$eventIncompatibleEvent] = $this->newEvent();
        $eventIncompatible = $this->definitionFrom($production, key: 'event-incompatible-test', supportedEventTypes: []);
        $this->expectDomainFailure(IncompatibleWebsiteTemplate::class, $eventIncompatibleEvent, $eventIncompatible);

        [$sectionIncompatibleEvent] = $this->newEvent();
        $sectionIncompatible = $this->definitionFrom(
            $production,
            key: 'section-incompatible-test',
            supportedSectionTypes: array_values(array_diff($production->supportedSectionTypes, ['rsvp'])),
        );
        $this->expectDomainFailure(IncompatibleWebsiteTemplate::class, $sectionIncompatibleEvent, $sectionIncompatible);
    }

    public function test_duplicate_initialization_returns_conflict_without_mutating_the_existing_website(): void
    {
        [$event, $owner] = $this->newEvent();
        $url = "/api/events/{$event->id}/website";

        $this->actingAs($owner)->postJson($url, ['templateKey' => WebsiteTemplateRegistry::MODERN_EDITORIAL_V1])->assertCreated();
        $website = $event->website()->sole();
        $before = $website->sections()->get()->map->only(['id', 'type', 'sort_order', 'is_enabled', 'content', 'appearance'])->all();

        $this->actingAs($owner)->postJson($url, ['templateKey' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1])
            ->assertConflict()
            ->assertJsonPath('message', 'This Event already has a Website.');

        $this->assertDatabaseCount('websites', 1);
        $this->assertDatabaseCount('website_sections', 3);
        $this->assertSame(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1, $website->refresh()->template_key);
        $this->assertSame($before, $website->sections()->get()->map->only(['id', 'type', 'sort_order', 'is_enabled', 'content', 'appearance'])->all());
    }

    /** @return array{Event, User} */
    private function newEvent(): array
    {
        $owner = User::factory()->create();

        return [app(CreateEvent::class)->handle($owner, ['name' => fake()->words(3, true)]), $owner];
    }

    /** @param class-string<\Throwable> $exception */
    private function expectDomainFailure(string $exception, Event $event, WebsiteTemplateDefinition $definition): void
    {
        $registry = new WebsiteTemplateRegistry([$definition->key => $definition]);
        $sections = new WebsiteSectionRegistry;
        $action = new InitializeEventWebsite(
            $registry,
            new WebsiteCapabilityResolver($registry, $sections),
            $sections,
            new InitializeWebsiteSections($sections, $registry),
        );

        try {
            $action->handle($event, $definition->key);
            $this->fail("Expected {$exception}.");
        } catch (\Throwable $caught) {
            $this->assertInstanceOf($exception, $caught);
        }
        $this->assertFalse($event->website()->exists());
    }

    /** @param list<EventType>|null $supportedEventTypes
     * @param  list<string>|null  $supportedSectionTypes
     */
    private function definitionFrom(
        WebsiteTemplateDefinition $template,
        string $key,
        bool $enabled = true,
        ?array $supportedEventTypes = null,
        ?array $supportedSectionTypes = null,
    ): WebsiteTemplateDefinition {
        return new WebsiteTemplateDefinition(
            key: $key,
            displayName: 'Test Template',
            description: 'Test only.',
            styleTags: ['Test'],
            enabled: $enabled,
            supportedEventTypes: $supportedEventTypes ?? $template->supportedEventTypes,
            supportedSectionTypes: $supportedSectionTypes ?? $template->supportedSectionTypes,
            designOptions: $template->designOptions,
            defaultDesignSettings: $template->defaultDesignSettings,
            designLibrary: $template->designLibrary,
            sectionAppearanceOptions: $template->sectionAppearanceOptions,
            sectionAppearanceDefaults: $template->sectionAppearanceDefaults,
        );
    }
}
