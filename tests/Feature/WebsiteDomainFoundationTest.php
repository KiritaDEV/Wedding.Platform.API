<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Enums\EventMembershipRole;
use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class WebsiteDomainFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_w1_rollout_backfills_one_empty_website_for_a_pre_existing_event(): void
    {
        $event = Event::factory()->create();
        Schema::dropIfExists('website_sections');
        Schema::dropIfExists('websites');

        $websiteMigration = require database_path('migrations/2026_08_14_000000_create_websites_table.php');
        $websiteMigration->up();
        $sectionMigration = require database_path('migrations/2026_08_14_000001_create_website_sections_table.php');
        $sectionMigration->up();

        $website = Website::query()->where('event_id', $event->id)->sole();

        $this->assertTrue(Str::isUlid($website->id));
        $this->assertDatabaseCount('websites', 1);
        $this->assertDatabaseCount('website_sections', 0);
    }

    public function test_event_has_one_website_and_website_belongs_to_event(): void
    {
        $event = Event::factory()->create();
        $website = Website::factory()->for($event)->create();

        $this->assertTrue($event->website->is($website));
        $this->assertTrue($website->event->is($event));
        $this->assertTrue(Str::isUlid($website->id));
    }

    public function test_event_supports_multiple_website_projects_while_legacy_relationship_resolves_single_case(): void
    {
        $event = Event::factory()->create();
        $legacyWebsite = Website::factory()->for($event)->create();

        $this->assertInstanceOf(HasMany::class, $event->websiteProjects());
        $this->assertTrue($event->website->is($legacyWebsite));

        $secondWebsite = Website::factory()->for($event)->create();

        $this->assertCount(2, $event->websiteProjects()->get());
        $this->assertTrue($event->websiteProjects->contains($legacyWebsite));
        $this->assertTrue($event->websiteProjects->contains($secondWebsite));
    }

    public function test_website_name_is_required_and_factory_supplies_the_bounded_default(): void
    {
        $website = Website::factory()->create();

        $this->assertSame(Website::DEFAULT_NAME, $website->name);
        $this->assertSame(100, Website::MAX_NAME_LENGTH);

        $this->expectException(QueryException::class);

        DB::table('websites')->insert([
            'id' => (string) Str::ulid(),
            'event_id' => Event::factory()->create()->id,
            'template_key' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
            'design_settings' => json_encode($website->design_settings, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_same_section_type_is_unique_per_project_not_per_event(): void
    {
        $event = Event::factory()->create();
        $first = Website::factory()->for($event)->create();
        $second = Website::factory()->for($event)->create();

        WebsiteSection::factory()->for($first)->forType('hero')->create();
        WebsiteSection::factory()->for($second)->forType('hero')->create();

        $this->assertSame(2, WebsiteSection::query()->where('type', 'hero')->count());

        $this->expectException(QueryException::class);

        WebsiteSection::factory()->for($first)->forType('hero')->create();
    }

    public function test_website_sections_preserve_semantic_content_and_cast_domain_state(): void
    {
        $website = Website::factory()->create();
        $section = $website->sections()->create([
            'type' => 'hero',
            'sort_order' => 3,
            'is_enabled' => false,
            'content' => [
                'heading' => 'Neil & Hazel',
                'subheading' => 'December 22, 2026',
            ],
            'appearance' => WebsiteSectionAppearance::DEFAULT,
        ])->refresh();

        $this->assertTrue(Str::isUlid($section->id));
        $this->assertTrue($section->website->is($website));
        $this->assertSame('hero', $section->type);
        $this->assertSame(3, $section->sort_order);
        $this->assertFalse($section->is_enabled);
        $this->assertSame([
            'heading' => 'Neil & Hazel',
            'subheading' => 'December 22, 2026',
        ], $section->content);
    }

    public function test_create_event_creates_owner_membership_without_a_website(): void
    {
        $creator = User::factory()->create();

        $event = app(CreateEvent::class)->handle($creator, ['name' => 'Neil & Hazel']);

        $this->assertDatabaseCount('websites', 0);
        $this->assertDatabaseCount('website_sections', 0);
        $this->assertNull($event->website);
        $this->assertSame(EventMembershipRole::Owner, $event->memberships()->sole()->role);
    }

    public function test_deleting_event_cascades_to_multiple_websites_and_sections(): void
    {
        $event = Event::factory()->create();
        $websites = Website::factory()->count(2)->for($event)->create();
        $sections = $websites->map(fn (Website $website) => WebsiteSection::factory()->for($website)->create());

        $event->delete();

        $websites->each(fn (Website $website) => $this->assertDatabaseMissing('websites', ['id' => $website->id]));
        $sections->each(fn (WebsiteSection $section) => $this->assertDatabaseMissing('website_sections', ['id' => $section->id]));
    }

    public function test_project_migration_backfills_name_without_copying_or_changing_existing_data(): void
    {
        $migration = require database_path('migrations/2026_08_22_000000_evolve_websites_into_projects.php');
        $website = Website::factory()->create([
            'template_key' => WebsiteTemplateRegistry::MODERN_EDITORIAL_V1,
            'design_settings' => ['colorTheme' => 'ink', 'fontSet' => 'editorial', 'artStyle' => 'clean'],
        ]);
        $section = WebsiteSection::factory()->for($website)->forType('hero')->create([
            'content' => ['headline' => 'Unchanged'],
        ]);

        $migration->down();

        $downIndexes = collect(Schema::getIndexes('websites'))->keyBy('name');
        $this->assertTrue($downIndexes->has('websites_event_id_unique'));
        $this->assertTrue($downIndexes['websites_event_id_unique']['unique']);
        $this->assertFalse($downIndexes->has('websites_event_id_index'));
        $this->assertDatabaseHas('website_sections', ['id' => $section->id, 'website_id' => $website->id]);

        $migration->up();

        $upIndexes = collect(Schema::getIndexes('websites'))->keyBy('name');
        $this->assertTrue($upIndexes->has('websites_event_id_index'));
        $this->assertFalse($upIndexes['websites_event_id_index']['unique']);
        $this->assertFalse($upIndexes->has('websites_event_id_unique'));

        $restoredWebsite = Website::query()->findOrFail($website->id);
        $this->assertSame('Website', $restoredWebsite->name);
        $this->assertSame(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1, $restoredWebsite->template_key);
        $this->assertSame($website->design_settings, $restoredWebsite->design_settings);
        $this->assertSame($section->id, $restoredWebsite->sections()->sole()->id);
        $this->assertSame(['headline' => 'Unchanged'], $restoredWebsite->sections()->sole()->content);
    }

    public function test_project_migration_preserves_classic_template_and_design_data(): void
    {
        $design = ['colorTheme' => 'olive', 'fontSet' => 'romantic', 'artStyle' => 'botanical'];
        $website = Website::factory()->create([
            'template_key' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
            'design_settings' => $design,
        ])->refresh();

        $this->assertSame(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, $website->template_key);
        $this->assertSame($design, $website->design_settings);
    }

    public function test_project_migration_rollback_refuses_duplicate_event_projects(): void
    {
        $event = Event::factory()->create();
        Website::factory()->count(2)->for($event)->create();
        $migration = require database_path('migrations/2026_08_22_000000_evolve_websites_into_projects.php');
        $indexesBefore = Schema::getIndexes('websites');

        try {
            $migration->down();
            $this->fail('Rollback should reject duplicate Website Projects.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('has multiple Website Projects', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('websites', 'name'));
        $this->assertSame($indexesBefore, Schema::getIndexes('websites'));
        $this->assertFalse(Schema::hasTable('website_sections_p11_backup'));
        $this->assertSame(2, $event->websiteProjects()->count());
    }
}
