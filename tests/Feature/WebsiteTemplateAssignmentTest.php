<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Enums\EventMembershipRole;
use App\Enums\PlatformRole;
use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteTemplateAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_initialization_assigns_requested_template_with_sections_and_owner_membership(): void
    {
        $creator = User::factory()->create();
        $event = app(CreateEvent::class)->handle($creator, ['name' => 'A Wedding']);

        $this->initializeWebsite($event);

        $this->assertSame(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, $event->website->template_key);
        $this->assertSame(3, $event->website->sections()->count());
        $this->assertSame(EventMembershipRole::Owner, $event->memberships()->sole()->role);
        $this->assertSame(PlatformRole::User, $creator->platform_role);
    }

    public function test_database_does_not_supply_a_template_when_raw_website_insert_omits_it(): void
    {
        $event = Event::factory()->create();
        $this->expectException(QueryException::class);

        DB::table('websites')->insert([
            'id' => (string) Str::ulid(),
            'event_id' => $event->id,
            'name' => Website::DEFAULT_NAME,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_historical_template_rollout_preserves_existing_sections(): void
    {
        $event = Event::factory()->create();
        Schema::dropIfExists('website_sections');
        Schema::dropIfExists('websites');
        (require database_path('migrations/2026_08_14_000000_create_websites_table.php'))->up();
        (require database_path('migrations/2026_08_14_000001_create_website_sections_table.php'))->up();
        (require database_path('migrations/2026_08_14_000002_initialize_wedding_website_sections.php'))->up();
        $website = Website::query()->where('event_id', $event->id)->sole();
        $before = $website->sections()->get()->map->only(['id', 'type', 'sort_order', 'is_enabled', 'content'])->all();
        $migration = require database_path('migrations/2026_08_14_000003_assign_default_website_templates.php');

        $migration->up();

        $this->assertSame(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, $website->refresh()->template_key);
        $this->assertSame($before, $website->sections()->get()->map->only(['id', 'type', 'sort_order', 'is_enabled', 'content'])->all());
    }
}
