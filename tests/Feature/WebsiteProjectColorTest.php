<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\CreateWebsiteProject;
use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Website\ProjectColorLibrary;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteProjectColorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);
    }

    public function test_project_without_custom_colors_normalizes_to_empty_without_rewriting_storage(): void
    {
        [$owner, $event, $website] = $this->project();
        $stored = $website->design_settings;
        unset($stored['customColors']);
        DB::table('websites')->where('id', $website->id)->update(['design_settings' => json_encode($stored, JSON_THROW_ON_ERROR)]);

        $this->actingAs($owner)->getJson($this->projectUrl($event->id, $website->id))
            ->assertOk()
            ->assertJsonPath('data.designSettings.customColors', []);

        $this->assertArrayNotHasKey('customColors', json_decode(
            DB::table('websites')->where('id', $website->id)->value('design_settings'),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    public function test_add_normalizes_value_generates_stable_id_and_round_trips(): void
    {
        [$owner, $event, $website] = $this->project();
        $url = $this->projectUrl($event->id, $website->id).'/colors';

        $response = $this->actingAs($owner)->postJson($url, ['value' => '#1a1a1a'])
            ->assertCreated()
            ->assertJsonPath('data.designSettings.customColors.0.value', '#1A1A1A');

        $id = $response->json('data.designSettings.customColors.0.id');
        $this->assertIsString($id);
        $this->assertStringStartsWith(ProjectColorLibrary::ID_PREFIX, $id);
        $this->assertTrue(Str::isUlid(substr($id, strlen(ProjectColorLibrary::ID_PREFIX))));

        $this->actingAs($owner)->getJson($this->projectUrl($event->id, $website->id))
            ->assertOk()
            ->assertJsonPath('data.designSettings.customColors.0.id', $id)
            ->assertJsonPath('data.designSettings.customColors.0.value', '#1A1A1A');
    }

    public function test_duplicate_normalized_value_and_invalid_forms_are_rejected(): void
    {
        [$owner, $event, $website] = $this->project();
        $url = $this->projectUrl($event->id, $website->id).'/colors';
        $this->actingAs($owner)->postJson($url, ['value' => '#1a1a1a'])->assertCreated();
        $this->actingAs($owner)->postJson($url, ['value' => '#1A1A1A'])->assertUnprocessable();

        foreach (['#FFF', '#11223344', 'rgb(1,2,3)', 'red', 'var(--accent)', 'linear-gradient(red, blue)', 'url(https://example.com/a.png)'] as $value) {
            $this->actingAs($owner)->postJson($url, compact('value'))->assertUnprocessable();
        }
    }

    public function test_client_cannot_supply_or_replace_ids_or_the_collection(): void
    {
        [$owner, $event, $website] = $this->project();
        $url = $this->projectUrl($event->id, $website->id).'/colors';

        $this->actingAs($owner)->postJson($url, ['id' => 'project-color-'.Str::ulid(), 'value' => '#123456'])
            ->assertUnprocessable();
        $this->actingAs($owner)->postJson($url, ['value' => '#123456', 'customColors' => []])
            ->assertUnprocessable();

        $settings = $website->refresh()->design_settings;
        $settings['customColors'] = [[
            'id' => ProjectColorLibrary::ID_PREFIX.(string) Str::ulid(),
            'value' => '#123456',
        ]];
        $this->actingAs($owner)->putJson($this->projectUrl($event->id, $website->id).'/design', ['designSettings' => $settings])
            ->assertUnprocessable();
    }

    public function test_maximum_32_is_enforced_and_insertion_order_is_preserved(): void
    {
        [$owner, $event, $website] = $this->project();
        $colors = [];
        for ($index = 0; $index < ProjectColorLibrary::MAXIMUM; $index++) {
            $colors[] = [
                'id' => ProjectColorLibrary::ID_PREFIX.(string) Str::ulid(),
                'value' => '#'.strtoupper(str_pad(dechex($index), 6, '0', STR_PAD_LEFT)),
            ];
        }
        $settings = $website->design_settings;
        $settings['customColors'] = $colors;
        $website->update(['design_settings' => $settings]);

        $this->actingAs($owner)->postJson($this->projectUrl($event->id, $website->id).'/colors', ['value' => '#FFFFFF'])
            ->assertUnprocessable();
        $this->assertSame($colors, $website->refresh()->design_settings['customColors']);
    }

    public function test_capability_is_additive_and_template_colors_are_not_copied(): void
    {
        [$owner, $event, $website] = $this->project();

        $this->actingAs($owner)->getJson($this->projectUrl($event->id, $website->id))
            ->assertOk()
            ->assertJsonPath('data.template.capabilities.projectColorLibrary', [
                'enabled' => true,
                'maximum' => 32,
                'format' => 'opaqueHex',
            ])
            ->assertJsonPath('data.designSettings.customColors', []);
        $this->assertSame([], $website->refresh()->design_settings['customColors']);
    }

    /** @return array{User, Event, Website} */
    private function project(): array
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => 'A Wedding']);
        $website = app(CreateWebsiteProject::class)->handle($event, 'Website', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);

        return [$owner, $event, $website];
    }

    private function projectUrl(string $eventId, string $websiteId): string
    {
        return "/api/events/{$eventId}/websites/{$websiteId}";
    }
}
