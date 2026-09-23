<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Website\ProjectColorLibrary;
use App\Website\WebsiteSectionAppearance;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteSectionAppearanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);
    }

    public function test_new_sections_receive_explicit_default_appearance(): void
    {
        $event = app(CreateEvent::class)->handle(User::factory()->create(), ['name' => 'A Wedding']);
        $this->initializeWebsite($event);

        $this->assertCount(3, $event->website->sections);
        $event->website->sections->each(fn ($section) => $this->assertSame(
            in_array($section->type, ['hero', 'gallery'], true) ? ['shared' => WebsiteSectionAppearance::DEFAULT] : WebsiteSectionAppearance::DEFAULT,
            $section->appearance,
        ));
    }

    public function test_database_requires_appearance_without_a_default(): void
    {
        $website = Website::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('website_sections')->insert([
            'id' => (string) Str::ulid(),
            'website_id' => $website->id,
            'type' => 'hero',
            'sort_order' => 1,
            'is_enabled' => true,
            'content' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_draft_exposes_appearance_and_template_owned_options(): void
    {
        [$event, $owner] = $this->eventWithOwner();

        $response = $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk();
        $response->assertJsonPath('data.sections.0.appearance', ['shared' => WebsiteSectionAppearance::DEFAULT])
            ->assertJsonPath('data.sections.0.appearanceOptions.headingAlignments.0.key', 'inherit')
            ->assertJsonCount(4, 'data.sections.0.appearanceOptions.headingAlignments')
            ->assertJsonCount(4, 'data.sections.0.appearanceOptions.bodyAlignments')
            ->assertJsonCount(4, 'data.sections.0.appearanceOptions.backgroundTreatments')
            ->assertJsonCount(4, 'data.sections.0.appearanceOptions.emphasisOptions')
            ->assertJsonPath('data.sections.0.presentationCapability', null)
            ->assertJsonPath('data.template.capabilities.sections.0.defaultPresentation', null)
            ->assertJsonPath('data.sections.1.presentationCapability', null);
    }

    public function test_valid_update_returns_authoritative_draft_and_preserves_section_data(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $section = $event->website->sections()->where('type', 'gallery')->firstOrFail();
        $before = $section->only(['content', 'sort_order', 'is_enabled']);
        $branch = [
            'headingAlignment' => 'right',
            'bodyAlignment' => 'left',
            'backgroundTreatment' => 'accent',
            'emphasis' => 'featured',
        ];
        $appearance = ['shared' => $branch];

        $this->actingAs($owner)
            ->putJson("/api/events/{$event->id}/website/sections/{$section->id}/appearance", compact('appearance'))
            ->assertOk()
            ->assertJsonPath('data.sections.1.appearance', $appearance);

        $this->assertSame($appearance, $section->refresh()->appearance);
        $this->assertSame($before, $section->only(['content', 'sort_order', 'is_enabled']));
    }

    public function test_hero_surface_appearance_is_sparse_and_rejects_obsolete_presentation_fields(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $url = "/api/events/{$event->id}/website/sections/{$hero->id}/appearance";

        $appearance = ['shared' => [...WebsiteSectionAppearance::DEFAULT, 'height' => ['unit' => 'svh', 'value' => 100], 'backgroundImageOpacity' => 45]];
        $this->actingAs($owner)->putJson($url, compact('appearance'))->assertOk()
            ->assertJsonPath('data.sections.0.appearance.shared.height.unit', 'svh')
            ->assertJsonPath('data.sections.0.appearance.shared.height.value', 100)
            ->assertJsonPath('data.sections.0.appearance.shared.backgroundImageOpacity', 45);

        $this->actingAs($owner)->putJson($url, ['appearance' => ['shared' => [...WebsiteSectionAppearance::DEFAULT, 'backgroundImageOpacity' => 100]]])
            ->assertOk()->assertJsonMissingPath('data.sections.0.appearance.shared.height')->assertJsonMissingPath('data.sections.0.appearance.shared.backgroundImageOpacity');
        foreach ([25, 100, 150] as $value) {
            $this->actingAs($owner)->putJson($url, ['appearance' => ['shared' => [...WebsiteSectionAppearance::DEFAULT, 'height' => ['unit' => 'svh', 'value' => $value]]]])->assertOk();
        }
        foreach (['auto', 'screen', '75svh', ['unit' => 'vh', 'value' => 75], ['unit' => 'px', 'value' => 600], ['unit' => 'svh', 'value' => 24], ['unit' => 'svh', 'value' => 151], ['unit' => 'svh', 'value' => 75.5], ['unit' => 'svh', 'value' => 75, 'extra' => true]] as $height) {
            $this->actingAs($owner)->putJson($url, ['appearance' => ['shared' => [...WebsiteSectionAppearance::DEFAULT, 'height' => $height]]])->assertUnprocessable();
        }
        $this->actingAs($owner)->putJson($url, ['appearance' => ['shared' => [...WebsiteSectionAppearance::DEFAULT, 'presentation' => 'immersive']]])->assertUnprocessable();
    }

    public function test_hero_content_position_round_trips_at_owner_level_and_rejects_responsive_state(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $url = "/api/events/{$event->id}/website/sections/{$hero->id}/appearance";
        $branch = [...WebsiteSectionAppearance::DEFAULT, 'contentPosition' => 'center-end', 'innerSpacing' => ['top' => 'xl', 'right' => 's', 'bottom' => 'm']];
        $appearance = ['shared' => $branch];

        $this->actingAs($owner)->putJson($url, compact('appearance'))->assertOk()
            ->assertJsonPath('data.sections.0.appearance.shared.contentPosition', 'center-end');
        $this->assertSame($appearance, $hero->refresh()->appearance);

        $this->actingAs($owner)->putJson($url, ['appearance' => ['shared' => [...WebsiteSectionAppearance::DEFAULT, 'contentPosition' => 'center']]])
            ->assertOk()->assertJsonMissingPath('data.sections.0.appearance.shared.contentPosition');
        $this->actingAs($owner)->putJson($url, ['appearance' => [...WebsiteSectionAppearance::DEFAULT, 'contentPosition' => 'diagonal']])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['appearance' => [...WebsiteSectionAppearance::DEFAULT, 'responsive' => ['mobile' => ['contentPosition' => 'diagonal']]]])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['appearance' => [...WebsiteSectionAppearance::DEFAULT, 'innerSpacing' => ['topFox' => 'm']]])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['appearance' => [...WebsiteSectionAppearance::DEFAULT, 'innerSpacing' => ['top' => 'huge']]])->assertUnprocessable();
    }

    public function test_responsive_overrides_reject_unknown_keys_and_unsupported_values(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $url = "/api/events/{$event->id}/website/sections/{$hero->id}/appearance";
        $base = [...WebsiteSectionAppearance::DEFAULT, 'presentation' => 'classic'];

        foreach ([
            [...$base, 'responsive' => ['watch' => ['mediaPlacement' => 'top']]],
            [...$base, 'responsive' => ['mobile' => ['frameStyle' => 'heritage']]],
            [...$base, 'responsive' => ['mobile' => ['mediaPlacement' => 'diagonal']]],
            [...$base, 'responsive' => ['mobile' => ['mediaSpacing' => ['top' => 'none']]]],
        ] as $appearance) {
            $this->actingAs($owner)->putJson($url, compact('appearance'))->assertUnprocessable();
        }

        $this->assertSame(['shared' => WebsiteSectionAppearance::DEFAULT], $hero->refresh()->appearance);
    }

    public function test_blank_decorative_appearance_saves_and_reloads_sparse_shared_intent(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $base = "/api/events/{$event->id}/websites/{$event->website->id}/sections";
        $sections = $this->actingAs($owner)->postJson($base, ['type' => 'blank'])->assertCreated()->json('data.sections');
        $blank = collect($sections)->firstWhere('type', 'blank');
        $appearance = [
            ...WebsiteSectionAppearance::DEFAULT,
            'backgroundTreatment' => 'custom',
            'innerSpacing' => ['top' => 'xl', 'right' => 'xs', 'bottom' => 'm', 'left' => 's'],
            'decorativeAppearance' => [
                'background' => ['colorId' => 'terracotta-canvas', 'texture' => 'paper', 'textureStrength' => 55, 'pattern' => 'botanical', 'patternStrength' => 50, 'overlay' => 'warm'],
                'frame' => ['style' => 'fine', 'size' => 50, 'strength' => 100, 'colorId' => 'terracotta-accent'],
            ],
        ];
        $url = "/api/events/{$event->id}/website/sections/{$blank['id']}/appearance";
        $appearance = ['shared' => $appearance];
        $this->actingAs($owner)->putJson($url, compact('appearance'))->assertOk();
        $stored = $event->website->sections()->findOrFail($blank['id'])->appearance;
        $this->assertSame($appearance, $stored);
        $reloaded = collect($this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk()->json('data.sections'))->firstWhere('id', $blank['id']);
        $this->assertEquals($appearance, $reloaded['appearance']);

        $sparse = ['shared' => [...WebsiteSectionAppearance::DEFAULT, 'decorativeAppearance' => ['frame' => ['style' => 'fine']]]];
        $this->actingAs($owner)->putJson($url, ['appearance' => $sparse])->assertOk();
        $this->assertSame($sparse, $event->website->sections()->findOrFail($blank['id'])->appearance);

        foreach (['headingAlignment' => 'center', 'bodyAlignment' => 'right', 'emphasis' => 'featured'] as $field => $value) {
            $invalid = $sparse;
            $invalid['shared'][$field] = $value;
            $this->actingAs($owner)->putJson($url, ['appearance' => $invalid])->assertUnprocessable();
        }
        $this->actingAs($owner)->putJson("{$base}/{$blank['id']}/design-defaults", ['designDefaults' => ['headingFontId' => 'classic-serif']])
            ->assertUnprocessable()->assertJsonValidationErrors('designDefaults');

        $model = $event->website->sections()->findOrFail($blank['id']);
        $model->appearance = ['shared' => [...$sparse['shared'], 'headingAlignment' => 'right', 'emphasis' => 'featured'], 'designDefaults' => ['headingFontId' => 'legacy']];
        $model->save();
        $normalized = collect($this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk()->json('data.sections'))->firstWhere('id', $blank['id']);
        $this->assertSame('inherit', $normalized['appearance']['shared']['headingAlignment']);
        $this->assertSame('inherit', $normalized['appearance']['shared']['emphasis']);
        $this->assertSame([], $normalized['designDefaults']);
    }

    public function test_gallery_and_rsvp_accept_generic_section_decorative_appearance(): void
    {
        [$event, $owner] = $this->eventWithOwner();

        foreach (['gallery', 'rsvp'] as $type) {
            $section = $event->website->sections()->where('type', $type)->sole();
            $branch = [
                ...WebsiteSectionAppearance::DEFAULT,
                'decorativeAppearance' => [
                    'background' => ['texture' => 'paper', 'textureStrength' => 55, 'pattern' => 'botanical', 'patternStrength' => 50, 'overlay' => 'warm'],
                    'frame' => ['style' => 'ornamental', 'size' => 200, 'strength' => 0, 'colorId' => 'terracotta-accent'],
                ],
            ];
            $appearance = $type === 'gallery' ? ['shared' => $branch] : $branch;
            $url = "/api/events/{$event->id}/website/sections/{$section->id}/appearance";
            $this->actingAs($owner)->putJson($url, compact('appearance'))->assertOk();
            $this->assertSame($appearance, $section->refresh()->appearance);
        }
    }

    public function test_frame_numeric_boundaries_and_unknown_values_are_rejected(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $section = $event->website->sections()->where('type', 'gallery')->sole();
        $url = "/api/events/{$event->id}/website/sections/{$section->id}/appearance";

        foreach ([
            ['size' => 49], ['size' => 201], ['size' => 50.5],
            ['strength' => -1], ['strength' => 101], ['strength' => 25.5],
            ['colorId' => 'missing-frame-color'], ['inset' => 20],
        ] as $invalidFrame) {
            $appearance = ['shared' => [...WebsiteSectionAppearance::DEFAULT, 'decorativeAppearance' => ['frame' => ['style' => 'fine', ...$invalidFrame]]]];
            $this->actingAs($owner)->putJson($url, compact('appearance'))->assertUnprocessable();
        }
    }

    public function test_frame_and_height_overrides_round_trip_independently_in_every_composition_appearance_branch(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $content = $hero->content;
        foreach (['desktop', 'tablet', 'mobile'] as $viewport) {
            $content['compositions']['custom'][$viewport] = $content['compositions']['shared'];
        }
        $hero->content = $content;
        $hero->save();

        $branch = fn (int $size, int $strength, int $height): array => [...WebsiteSectionAppearance::DEFAULT, 'height' => ['unit' => 'svh', 'value' => $height], 'decorativeAppearance' => ['frame' => ['style' => 'fine', 'size' => $size, 'strength' => $strength, 'colorId' => 'terracotta-accent']]];
        $appearance = ['shared' => $branch(100, 42, 75), 'custom' => [
            'desktop' => $branch(50, 0, 25),
            'tablet' => $branch(150, 50, 100),
            'mobile' => $branch(200, 100, 150),
        ]];
        $url = "/api/events/{$event->id}/website/sections/{$hero->id}/appearance";
        $this->actingAs($owner)->putJson($url, compact('appearance'))->assertOk();
        $this->assertSame($appearance, $hero->refresh()->appearance);
    }

    public function test_project_color_id_is_valid_for_frame_color_and_remains_sparse(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $projectColorId = ProjectColorLibrary::ID_PREFIX.(string) Str::ulid();
        $settings = $event->website->design_settings;
        $settings['customColors'] = [['id' => $projectColorId, 'value' => '#123456']];
        $event->website->design_settings = $settings;
        $event->website->save();
        $section = $event->website->sections()->where('type', 'gallery')->sole();
        $appearance = ['shared' => [...WebsiteSectionAppearance::DEFAULT, 'decorativeAppearance' => ['frame' => ['style' => 'fine', 'colorId' => $projectColorId]]]];
        $url = "/api/events/{$event->id}/website/sections/{$section->id}/appearance";
        $this->actingAs($owner)->putJson($url, compact('appearance'))->assertOk();
        $this->assertSame($appearance, $section->refresh()->appearance);
    }

    public function test_invalid_missing_and_extra_appearance_values_are_rejected(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $section = $event->website->sections()->firstOrFail();
        $url = "/api/events/{$event->id}/website/sections/{$section->id}/appearance";
        $invalid = [
            ['headingAlignment' => 'diagonal', 'bodyAlignment' => 'inherit', 'backgroundTreatment' => 'inherit', 'emphasis' => 'inherit'],
            ['headingAlignment' => 'inherit', 'bodyAlignment' => 'justify-everything', 'backgroundTreatment' => 'inherit', 'emphasis' => 'inherit'],
            ['headingAlignment' => 'inherit', 'bodyAlignment' => 'inherit', 'backgroundTreatment' => '#ff0000', 'emphasis' => 'inherit'],
            ['headingAlignment' => 'inherit', 'bodyAlignment' => 'inherit', 'backgroundTreatment' => 'inherit', 'emphasis' => 'huge'],
            ['headingAlignment' => 'inherit', 'bodyAlignment' => 'inherit', 'backgroundTreatment' => 'inherit'],
            [...WebsiteSectionAppearance::DEFAULT, 'customCss' => 'body{}'],
            [...WebsiteSectionAppearance::DEFAULT, 'frameStyle' => 'heritage'],
        ];

        foreach ($invalid as $appearance) {
            $this->actingAs($owner)->putJson($url, compact('appearance'))->assertUnprocessable();
            $this->assertSame(['shared' => WebsiteSectionAppearance::DEFAULT], $section->refresh()->appearance);
        }
    }

    public function test_endpoint_requires_event_access_and_scopes_section_to_event(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        [$otherEvent] = $this->eventWithOwner();
        $section = $event->website->sections()->firstOrFail();
        $otherSection = $otherEvent->website->sections()->firstOrFail();
        $payload = ['appearance' => WebsiteSectionAppearance::DEFAULT];
        $url = "/api/events/{$event->id}/website/sections/{$section->id}/appearance";

        $this->putJson($url, $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->putJson($url, $payload)->assertForbidden();
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$otherSection->id}/appearance", $payload)->assertNotFound();
    }

    public function test_w9_rollout_and_rollback_reapply_preserve_existing_sections(): void
    {
        $event = Event::factory()->create();
        Schema::dropIfExists('website_sections');
        Schema::dropIfExists('websites');
        (require database_path('migrations/2026_08_14_000000_create_websites_table.php'))->up();
        (require database_path('migrations/2026_08_14_000001_create_website_sections_table.php'))->up();
        (require database_path('migrations/2026_08_14_000002_initialize_wedding_website_sections.php'))->up();
        (require database_path('migrations/2026_08_14_000003_assign_default_website_templates.php'))->up();
        (require database_path('migrations/2026_08_15_000000_add_design_settings_to_websites.php'))->up();
        $website = Website::query()->where('event_id', $event->id)->sole();
        $before = $this->sectionSnapshot($website->id);
        $migration = require database_path('migrations/2026_08_15_000001_add_appearance_to_website_sections.php');

        $migration->up();
        $website->sections()->get()->each(fn ($section) => $this->assertSame(
            in_array($section->type, ['hero', 'blank'], true) ? ['shared' => WebsiteSectionAppearance::DEFAULT] : WebsiteSectionAppearance::DEFAULT,
            $section->appearance,
        ));
        $this->assertSame($before, $this->sectionSnapshot($website->id));
        $migration->down();
        $this->assertSame($before, $this->sectionSnapshot($website->id));
        $migration->up();
        $this->assertSame($before, $this->sectionSnapshot($website->id));
    }

    /** @return array{Event, User} */
    private function eventWithOwner(): array
    {
        $owner = User::factory()->create();

        $event = app(CreateEvent::class)->handle($owner, ['name' => 'A Wedding']);
        $this->initializeWebsite($event);

        return [$event->refresh(), $owner];
    }

    /** @return array<int, array<string, mixed>> */
    private function sectionSnapshot(string $websiteId): array
    {
        return DB::table('website_sections')->where('website_id', $websiteId)->orderBy('sort_order')
            ->get(['id', 'content', 'sort_order', 'is_enabled', 'created_at', 'updated_at'])
            ->map(fn ($row): array => (array) $row)->all();
    }
}
