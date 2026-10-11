<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\CreateWebsiteProject;
use App\Exceptions\UnsupportedWebsiteSchemaVersion;
use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Website\WebsiteDraftNormalizer;
use App\Website\WebsiteSchema;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WebsiteSchemaVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);
    }

    public function test_migration_backfills_legacy_version_and_rollback_preserves_sections(): void
    {
        [$event] = $this->event();
        $website = app(CreateWebsiteProject::class)->handle($event, 'Existing', WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $websiteBefore = DB::table('websites')->where('id', $website->id)
            ->first(['id', 'event_id', 'name', 'template_key', 'design_settings', 'created_at', 'updated_at']);
        $sectionsBefore = DB::table('website_sections')->where('website_id', $website->id)
            ->orderBy('id')->get(['id', 'website_id', 'type', 'sort_order', 'is_enabled', 'content', 'appearance', 'created_at', 'updated_at'])->all();
        $migration = require database_path('migrations/2026_08_23_000000_add_schema_version_to_websites.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('websites', 'schema_version'));
        $this->assertEquals($sectionsBefore, DB::table('website_sections')->where('website_id', $website->id)
            ->orderBy('id')->get(['id', 'website_id', 'type', 'sort_order', 'is_enabled', 'content', 'appearance', 'created_at', 'updated_at'])->all());

        $migration->up();

        $this->assertTrue(Schema::hasColumn('websites', 'schema_version'));
        $this->assertSame(WebsiteSchema::LEGACY_SCHEMA_VERSION, Website::findOrFail($website->id)->schema_version);
        $this->assertEquals($websiteBefore, DB::table('websites')->where('id', $website->id)
            ->first(['id', 'event_id', 'name', 'template_key', 'design_settings', 'created_at', 'updated_at']));
        $this->assertEquals($sectionsBefore, DB::table('website_sections')->where('website_id', $website->id)
            ->orderBy('id')->get(['id', 'website_id', 'type', 'sort_order', 'is_enabled', 'content', 'appearance', 'created_at', 'updated_at'])->all());
    }

    public function test_v3_design_migration_adds_empty_sparse_defaults_and_preserves_legacy_values(): void
    {
        [$event] = $this->event();
        $classic = app(CreateWebsiteProject::class)->handle($event, 'Classic', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $modern = app(CreateWebsiteProject::class)->handle($event, 'Modern', WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $stored = [
            $classic->id => ['colorTheme' => 'terracotta', 'fontSet' => 'editorial', 'artStyle' => 'minimal', 'futureMetadata' => 'keep'],
            $modern->id => ['colorTheme' => 'plum', 'fontSet' => 'fashion', 'artStyle' => 'offset', 'futureMetadata' => 'keep'],
        ];
        foreach ($stored as $id => $settings) {
            DB::table('websites')->where('id', $id)->update([
                'design_settings' => json_encode($settings, JSON_THROW_ON_ERROR),
                'schema_version' => 2,
            ]);
        }
        $migration = require database_path('migrations/2026_08_24_000000_upgrade_website_design_settings_to_v3.php');

        $migration->up();
        foreach ($stored as $id => $settings) {
            $row = DB::table('websites')->where('id', $id)->first(['design_settings', 'schema_version']);
            $this->assertSame(3, $row->schema_version);
            $this->assertSame([...$settings, 'projectDefaults' => []], json_decode($row->design_settings, true, flags: JSON_THROW_ON_ERROR));
            $this->assertStringContainsString('"projectDefaults":{}', $row->design_settings);
        }

        $migration->down();
        foreach ($stored as $id => $settings) {
            $row = DB::table('websites')->where('id', $id)->first(['design_settings', 'schema_version']);
            $this->assertSame(2, $row->schema_version);
            $this->assertSame($settings, json_decode($row->design_settings, true, flags: JSON_THROW_ON_ERROR));
        }
    }

    public function test_v3_design_migration_rollback_refuses_to_discard_sparse_overrides_before_mutation(): void
    {
        [$event] = $this->event();
        $first = app(CreateWebsiteProject::class)->handle($event, 'First', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $second = app(CreateWebsiteProject::class)->handle($event, 'Second', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $settings = $second->design_settings;
        $settings['projectDefaults'] = ['headingFontId' => 'romantic-serif'];
        DB::table('websites')->where('id', $second->id)->update(['design_settings' => json_encode($settings, JSON_THROW_ON_ERROR)]);
        $before = DB::table('websites')->whereIn('id', [$first->id, $second->id])->orderBy('id')->get(['id', 'design_settings', 'schema_version'])->all();
        $migration = require database_path('migrations/2026_08_24_000000_upgrade_website_design_settings_to_v3.php');

        try {
            $migration->down();
            $this->fail('Rollback must refuse persisted Project Design Default overrides.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('persisted Project Design Default overrides', $exception->getMessage());
        }

        $this->assertEquals($before, DB::table('websites')->whereIn('id', [$first->id, $second->id])->orderBy('id')->get(['id', 'design_settings', 'schema_version'])->all());
    }

    public function test_future_version_returns_stable_conflict_for_project_and_legacy_reads(): void
    {
        [$event, $owner] = $this->event();
        $website = app(CreateWebsiteProject::class)->handle($event, 'Website', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        DB::table('websites')->where('id', $website->id)->update(['schema_version' => WebsiteSchema::CURRENT_SCHEMA_VERSION + 1]);
        $error = [
            'code' => 'website_schema_version_unsupported',
            'message' => 'This Website Project uses an unsupported schema version.',
        ];

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/websites/{$website->id}")
            ->assertConflict()->assertJson($error);
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")
            ->assertConflict()->assertJson($error);
    }

    public function test_negative_in_memory_version_is_rejected(): void
    {
        $website = Website::factory()->make(['schema_version' => -1]);

        $this->expectException(UnsupportedWebsiteSchemaVersion::class);
        app(WebsiteDraftNormalizer::class)->normalize($website);
    }

    /** @return array{Event, User} */
    private function event(): array
    {
        $owner = User::factory()->create();

        return [app(CreateEvent::class)->handle($owner, ['name' => fake()->words(3, true)]), $owner];
    }
}
