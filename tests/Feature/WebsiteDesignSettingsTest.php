<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\UpdateWebsiteDesignSettings;
use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\WebsiteSchema;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WebsiteDesignSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);
    }

    public function test_explicit_website_initialization_receives_template_default_design_settings(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);

        foreach ([WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, WebsiteTemplateRegistry::MODERN_EDITORIAL_V1] as $templateKey) {
            $event = app(CreateEvent::class)->handle(User::factory()->create(), ['name' => 'A Wedding']);
            $this->assertEquals($resolver->canonicalDesignDefaults($templateKey), $this->initializeWebsite($event, $templateKey)->design_settings);
        }
    }

    public function test_database_requires_explicit_design_settings_without_a_default(): void
    {
        $event = Event::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('websites')->insert([
            'id' => (string) Str::ulid(),
            'event_id' => $event->id,
            'template_key' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_draft_exposes_design_settings_and_template_options(): void
    {
        [$event, $owner] = $this->eventWithOwner();

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")
            ->assertOk()
            ->assertJsonPath('data.designSettings', $this->defaults())
            ->assertJsonPath('data.template.designOptions.colorThemes.0.key', 'terracotta')
            ->assertJsonCount(5, 'data.template.designOptions.colorThemes')
            ->assertJsonCount(3, 'data.template.designOptions.fontSets')
            ->assertJsonCount(4, 'data.template.designOptions.artStyles');
    }

    public function test_design_endpoint_persists_complete_valid_settings_and_preserves_sections(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $before = $event->website->sections()->get()->map->only(['id', 'content', 'sort_order', 'is_enabled'])->all();
        $settings = ['colorTheme' => 'sage', 'fontSet' => 'romantic', 'artStyle' => 'botanical', 'projectDefaults' => [], 'customColors' => []];

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/design", ['designSettings' => $settings])
            ->assertOk()->assertJsonPath('data.designSettings', $settings);

        $this->assertSame($settings, $event->website->refresh()->design_settings);
        $this->assertStringContainsString(
            '"projectDefaults":{}',
            DB::table('websites')->where('id', $event->website->id)->value('design_settings'),
        );
        $this->assertSame($before, $event->website->sections()->get()->map->only(['id', 'content', 'sort_order', 'is_enabled'])->all());
    }

    public function test_invalid_and_extra_design_values_are_rejected(): void
    {
        $website = Website::factory()->create();
        $action = app(UpdateWebsiteDesignSettings::class);

        foreach ([
            ['colorTheme' => 'neonRainbow', 'fontSet' => 'editorial', 'artStyle' => 'minimal', 'projectDefaults' => []],
            ['colorTheme' => 'olive', 'fontSet' => 'comicSans', 'artStyle' => 'minimal', 'projectDefaults' => []],
            ['colorTheme' => 'olive', 'fontSet' => 'modern', 'artStyle' => 'externalUrl', 'projectDefaults' => []],
            ['colorTheme' => 'olive', 'fontSet' => 'modern'],
            ['colorTheme' => 'olive', 'fontSet' => 'modern', 'artStyle' => 'clean', 'projectDefaults' => [], 'css' => 'body{}'],
        ] as $settings) {
            try {
                $action->handle($website, $settings);
                $this->fail('Invalid design settings should fail.');
            } catch (ValidationException) {
                $this->assertSame($this->defaults(), $website->refresh()->design_settings);
            }
        }
    }

    public function test_each_typed_global_design_control_accepts_and_rejects_values_for_both_templates(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);

        foreach ([WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, WebsiteTemplateRegistry::MODERN_EDITORIAL_V1] as $templateKey) {
            $owner = User::factory()->create();
            $event = app(CreateEvent::class)->handle($owner, ['name' => 'A Wedding']);
            $website = $this->initializeWebsite($event, $templateKey);
            $settings = $resolver->canonicalDesignDefaults($templateKey);
            $url = "/api/events/{$event->id}/website/design";

            foreach ($resolver->globalDesign($templateKey)->controls as $control) {
                $settings[$control->id->value] = $control->options[1]['key'];
                $this->actingAs($owner)->putJson($url, ['designSettings' => $settings])
                    ->assertOk()
                    ->assertJsonPath("data.designSettings.{$control->id->value}", $settings[$control->id->value]);

                $invalid = [...$settings, $control->id->value => 'unsupported-value'];
                $this->actingAs($owner)->putJson($url, ['designSettings' => $invalid])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors("designSettings.{$control->id->value}");
                $this->assertSame($settings, $website->refresh()->design_settings);
            }
        }
    }

    public function test_historical_invalid_design_settings_normalize_to_capability_defaults_without_mutation(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $website = $event->website;
        $defaults = app(WebsiteCapabilityResolver::class)->canonicalDesignDefaults($website->template_key);

        foreach ([
            ['fontSet' => 'editorial', 'artStyle' => 'minimal'],
            ['colorTheme' => 'unknown', 'fontSet' => 'editorial', 'artStyle' => 'minimal'],
            ['colorTheme' => [], 'fontSet' => 42, 'artStyle' => null],
        ] as $stored) {
            DB::table('websites')->where('id', $website->id)->update([
                'design_settings' => json_encode($stored, JSON_THROW_ON_ERROR),
            ]);
            $before = $website->refresh()->design_settings;

            $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")
                ->assertOk()
                ->assertJsonPath('data.schemaVersion', WebsiteSchema::CURRENT_SCHEMA_VERSION)
                ->assertJsonPath('data.designSettings', $defaults)
                ->assertJsonPath('data.template.designOptions', app(WebsiteTemplateRegistry::class)->get($website->template_key)->designOptions)
                ->assertJsonPath('data.template.capabilities.globalDesign.controls.0.default', $defaults['colorTheme']);

            $this->assertSame($before, $website->refresh()->design_settings);
        }
    }

    public function test_sparse_project_defaults_layer_independently_over_legacy_presets_and_reset_by_key_removal(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $url = "/api/events/{$event->id}/website/design";
        $settings = [
            'colorTheme' => 'terracotta',
            'fontSet' => 'editorial',
            'artStyle' => 'minimal',
            'projectDefaults' => [
                'headingFontId' => 'romantic-serif',
                'bodyFontId' => 'classic-serif',
                'headingColorId' => 'terracotta-accent',
                'bodyColorId' => 'terracotta-text',
                'accentColorId' => 'terracotta-accent',
            ],
        ];

        $this->actingAs($owner)->putJson($url, ['designSettings' => $settings])->assertOk()
            ->assertJsonPath('data.projectDesignDefaults.headingFontId', 'romantic-serif')
            ->assertJsonPath('data.projectDesignDefaults.bodyFontId', 'classic-serif')
            ->assertJsonPath('data.projectDesignDefaults.headingColorId', 'terracotta-accent')
            ->assertJsonPath('data.projectDesignDefaults.bodyColorId', 'terracotta-text')
            ->assertJsonPath('data.projectDesignDefaults.accentColorId', 'terracotta-accent');

        $settings['colorTheme'] = 'olive';
        $settings['fontSet'] = 'modern';
        $this->actingAs($owner)->putJson($url, ['designSettings' => $settings])->assertOk()
            ->assertJsonPath('data.designSettings.projectDefaults', $settings['projectDefaults'])
            ->assertJsonPath('data.projectDesignDefaults.headingFontId', 'romantic-serif')
            ->assertJsonPath('data.projectDesignDefaults.accentColorId', 'terracotta-accent');

        unset($settings['projectDefaults']['headingFontId'], $settings['projectDefaults']['accentColorId']);
        $this->actingAs($owner)->putJson($url, ['designSettings' => $settings])->assertOk()
            ->assertJsonPath('data.projectDesignDefaults.headingFontId', 'modern-sans')
            ->assertJsonPath('data.projectDesignDefaults.accentColorId', 'olive-accent');

        $settings['projectDefaults'] = [];
        $this->actingAs($owner)->putJson($url, ['designSettings' => $settings])->assertOk()
            ->assertJsonPath('data.projectDesignDefaults', [
                'headingFontId' => 'modern-sans',
                'bodyFontId' => 'modern-sans',
                'headingColorId' => 'olive-text',
                'bodyColorId' => 'olive-text',
                'accentColorId' => 'olive-accent',
            ]);
        $this->assertSame([], $event->website->refresh()->design_settings['projectDefaults']);
    }

    public function test_project_default_save_rejects_unknown_wrong_type_and_role_illegal_values(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $base = $this->defaults();
        $url = "/api/events/{$event->id}/website/design";
        $invalid = [
            ['unknown' => 'terracotta-text'],
            ['headingFontId' => 'missing-font'],
            ['bodyFontId' => 'editorial-serif'],
            ['headingColorId' => 'terracotta-canvas'],
            ['bodyColorId' => 'terracotta-accent'],
            ['accentColorId' => 'terracotta-text'],
            ['accentColorId' => '#9d5b45'],
            ['headingFontId' => null],
            ['headingFontId' => 42],
        ];

        foreach ($invalid as $projectDefaults) {
            $this->actingAs($owner)->putJson($url, ['designSettings' => [...$base, 'projectDefaults' => $projectDefaults]])
                ->assertUnprocessable();
        }
        foreach (['not-an-object', ['terracotta-text']] as $projectDefaults) {
            $this->actingAs($owner)->putJson($url, ['designSettings' => [...$base, 'projectDefaults' => $projectDefaults]])
                ->assertUnprocessable();
        }
        $this->assertEquals($base, $event->website->refresh()->design_settings);
    }

    public function test_historical_malformed_project_defaults_normalize_safely_without_database_writes(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $website = $event->website;
        $cases = [
            null,
            'invalid',
            ['unknown' => 'terracotta-text'],
            ['headingFontId' => 'missing', 'bodyFontId' => 'classic-serif'],
        ];

        foreach ($cases as $storedOverrides) {
            $stored = [...$this->defaults(), 'projectDefaults' => $storedOverrides];
            DB::table('websites')->where('id', $website->id)->update(['design_settings' => json_encode($stored, JSON_THROW_ON_ERROR)]);
            $before = DB::table('websites')->where('id', $website->id)->value('design_settings');
            $expected = is_array($storedOverrides) && ($storedOverrides['bodyFontId'] ?? null) === 'classic-serif'
                ? ['bodyFontId' => 'classic-serif']
                : [];

            $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk()
                ->assertJsonPath('data.designSettings.projectDefaults', $expected);
            $this->assertSame($before, DB::table('websites')->where('id', $website->id)->value('design_settings'));
        }
    }

    public function test_design_endpoint_requires_event_access(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $unrelated = User::factory()->create();
        $payload = ['designSettings' => $this->defaults()];
        $url = "/api/events/{$event->id}/website/design";

        $this->putJson($url, $payload)->assertUnauthorized();
        $this->actingAs($unrelated)->putJson($url, $payload)->assertForbidden();
        $this->actingAs($owner)->putJson($url, $payload)->assertOk();
    }

    public function test_w7_rollout_and_rollback_reapply_preserve_sections(): void
    {
        $event = Event::factory()->create();
        Schema::dropIfExists('website_sections');
        Schema::dropIfExists('websites');
        (require database_path('migrations/2026_08_14_000000_create_websites_table.php'))->up();
        (require database_path('migrations/2026_08_14_000001_create_website_sections_table.php'))->up();
        (require database_path('migrations/2026_08_14_000002_initialize_wedding_website_sections.php'))->up();
        (require database_path('migrations/2026_08_14_000003_assign_default_website_templates.php'))->up();
        $website = Website::query()->where('event_id', $event->id)->sole();
        $before = $website->sections()->get()->map->only(['id', 'content', 'sort_order', 'is_enabled'])->all();
        $migration = require database_path('migrations/2026_08_15_000000_add_design_settings_to_websites.php');

        $migration->up();
        $this->assertSame(
            app(WebsiteTemplateRegistry::class)->get(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1)->defaultDesignSettings,
            $website->refresh()->design_settings,
        );
        $this->assertSame($before, $website->sections()->get()->map->only(['id', 'content', 'sort_order', 'is_enabled'])->all());
        $migration->down();
        $this->assertSame($before, $website->sections()->get()->map->only(['id', 'content', 'sort_order', 'is_enabled'])->all());
        $migration->up();
        $this->assertSame($before, $website->sections()->get()->map->only(['id', 'content', 'sort_order', 'is_enabled'])->all());
    }

    /** @return array{Event, User} */
    private function eventWithOwner(): array
    {
        $owner = User::factory()->create();

        $event = app(CreateEvent::class)->handle($owner, ['name' => 'A Wedding']);
        $this->initializeWebsite($event);

        return [$event->refresh(), $owner];
    }

    /** @return array{colorTheme: string, fontSet: string, artStyle: string, projectDefaults: array<string, string>} */
    private function defaults(): array
    {
        return app(WebsiteCapabilityResolver::class)->canonicalDesignDefaults(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
    }
}
