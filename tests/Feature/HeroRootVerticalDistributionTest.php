<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\CreateWebsiteSection;
use App\Actions\Websites\PublishWebsite;
use App\Models\User;
use App\Models\Website;
use App\Website\Publishing\InvalidPublishedWebsiteSnapshot;
use App\Website\Publishing\PublishedWebsiteSnapshotBuilder;
use App\Website\Publishing\PublishedWebsiteSnapshotReader;
use App\Website\WebsiteSectionAppearance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeroRootVerticalDistributionTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Website
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => 'Distribution test']);
        $project = $this->initializeWebsite($event);
        $this->actingAs($owner)->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);

        return $project->fresh();
    }

    private function appearanceUrl(Website $project, string $id): string
    {
        return "/api/events/{$project->event_id}/websites/{$project->id}/sections/{$id}/appearance";
    }

    private function group(string $id): array
    {
        return ['id' => $id, 'type' => 'compositionGroup', 'editorName' => $id, 'children' => [
            ['id' => $id.'-text', 'type' => 'text', 'editorName' => 'Text', 'document' => ['type' => 'doc', 'children' => [
                ['type' => 'paragraph', 'children' => [['text' => 'Names']]],
            ]]],
        ], 'appearance' => ['outerSpacing' => ['top' => 's', 'bottom' => 'm']]];
    }

    public function test_old_and_explicit_packed_payloads_keep_sparse_defaults_and_save_reload(): void
    {
        $project = $this->project();
        $hero = $project->sections()->where('type', 'hero')->sole();
        $this->assertSame(['shared' => WebsiteSectionAppearance::DEFAULT], $hero->appearance);
        foreach ([null, 'packed', 'space-between'] as $value) {
            $branch = WebsiteSectionAppearance::DEFAULT;
            if ($value !== null) {
                $branch['rootVerticalDistribution'] = $value;
            }
            $this->draftPutJson($this->appearanceUrl($project, $hero->id), ['appearance' => ['shared' => $branch]])->assertOk();
            $saved = $hero->fresh()->appearance['shared'];
            if ($value === 'space-between') {
                $this->assertSame($value, $saved['rootVerticalDistribution']);
                $this->getJson("/api/events/{$project->event_id}/websites/{$project->id}")->assertOk()
                    ->assertJsonPath('data.sections.0.appearance.shared.rootVerticalDistribution', $value);
            } else {
                $this->assertSame(WebsiteSectionAppearance::DEFAULT, $saved);
            }
        }
    }

    public function test_invalid_values_and_other_section_types_are_rejected_without_mutation(): void
    {
        $project = $this->project();
        $hero = $project->sections()->where('type', 'hero')->sole();
        foreach ([null, false, 1, [], 'evenly', 'SPACE-BETWEEN'] as $value) {
            $this->draftPutJson($this->appearanceUrl($project, $hero->id), ['appearance' => ['shared' => [...WebsiteSectionAppearance::DEFAULT, 'rootVerticalDistribution' => $value]]])
                ->assertUnprocessable()->assertJsonValidationErrors('appearance.rootVerticalDistribution');
        }
        $this->assertSame(['shared' => WebsiteSectionAppearance::DEFAULT], $hero->fresh()->appearance);
        $blank = app(CreateWebsiteSection::class)->handle($project, 'blank');
        foreach ([$blank, ...$project->sections()->whereIn('type', ['gallery', 'rsvp'])->get()] as $section) {
            $before = $section->appearance;
            $appearance = $section->type === 'rsvp' ? ['rootVerticalDistribution' => 'packed'] : ['shared' => [...WebsiteSectionAppearance::DEFAULT, 'rootVerticalDistribution' => 'space-between']];
            $this->draftPutJson($this->appearanceUrl($project, $section->id), compact('appearance'))->assertUnprocessable();
            $this->assertSame($before, $section->fresh()->appearance);
        }
    }

    public function test_atomic_save_retains_groups_spacing_and_exact_device_ownership(): void
    {
        $project = $this->project();
        $hero = $project->sections()->where('type', 'hero')->sole();
        $elements = [$this->group('top'), $this->group('bottom')];
        $flow = ['elements' => $elements, 'order' => array_map(fn ($element) => ['kind' => 'element', 'id' => $element['id']], $elements)];
        $content = $hero->content;
        $content['compositions']['custom']['mobile'] = ['childFlow' => $flow];
        $appearance = $hero->appearance;
        $appearance['custom']['mobile'] = [...WebsiteSectionAppearance::DEFAULT, 'rootVerticalDistribution' => 'space-between', 'height' => ['unit' => 'svh', 'value' => 100], 'innerSpacing' => ['top' => 'xl', 'bottom' => 'l'], 'contentPosition' => 'bottom-center'];
        $this->patchJson("/api/events/{$project->event_id}/websites/{$project->id}/draft", ['sections' => [['id' => $hero->id, 'content' => $content, 'appearance' => $appearance]]], ['X-Website-Draft-Revision' => $project->fresh()->draft_revision])->assertOk();
        $saved = $hero->fresh();
        $this->assertSame($appearance, $saved->appearance);
        $this->assertSame($elements, $saved->content['compositions']['custom']['mobile']['childFlow']['elements']);
        $this->assertSame($hero->content['compositions']['shared'], $saved->content['compositions']['shared']);
        $this->assertArrayNotHasKey('rootVerticalDistribution', $saved->appearance['shared']);
        $this->getJson("/api/events/{$project->event_id}/websites/{$project->id}")->assertOk()
            ->assertJsonPath('data.sections.0.appearance.custom.mobile.rootVerticalDistribution', 'space-between');
        app(PublishWebsite::class)->handle($project->event, $project);
        $snapshot = app(PublishedWebsiteSnapshotReader::class)->decode($project->fresh()->getRawOriginal('published_snapshot'));
        $frozen = json_decode($snapshot->toJson(), true)['website']['sections'][0];
        $this->assertSame('space-between', $frozen['appearance']['custom']['mobile']['rootVerticalDistribution']);
        $this->assertSame($elements, $frozen['content']['compositions']['custom']['mobile']['childFlow']['elements']);
        $this->assertArrayNotHasKey('rootVerticalDistribution', $frozen['appearance']['shared']);
    }

    public function test_old_and_new_snapshots_decode_and_draft_changes_do_not_leak_until_republish(): void
    {
        $project = $this->project();
        $reader = app(PublishedWebsiteSnapshotReader::class);
        $old = app(PublishedWebsiteSnapshotBuilder::class)->build($project)->toJson();
        $this->assertArrayNotHasKey('rootVerticalDistribution', json_decode($reader->decode($old)->toJson(), true)['website']['sections'][0]['appearance']['shared']);
        app(PublishWebsite::class)->handle($project->event, $project);
        $publishedBeforeEdit = $project->fresh()->getRawOriginal('published_snapshot');
        $hero = $project->sections()->where('type', 'hero')->sole();
        $appearance = ['shared' => [...WebsiteSectionAppearance::DEFAULT, 'rootVerticalDistribution' => 'space-between']];
        $this->draftPutJson($this->appearanceUrl($project, $hero->id), compact('appearance'))->assertOk();
        $this->assertSame($publishedBeforeEdit, $project->fresh()->getRawOriginal('published_snapshot'));
        app(PublishWebsite::class)->handle($project->event, $project);
        $new = $project->fresh()->getRawOriginal('published_snapshot');
        $decoded = json_decode($reader->decode($new)->toJson(), true);
        $this->assertSame('space-between', $decoded['website']['sections'][0]['appearance']['shared']['rootVerticalDistribution']);
        foreach (['invalid', null] as $value) {
            $bad = $decoded;
            $bad['website']['sections'][0]['appearance']['shared']['rootVerticalDistribution'] = $value;
            try {
                $reader->decode(json_encode($bad, JSON_THROW_ON_ERROR));
                $this->fail('Invalid snapshot distribution was accepted.');
            } catch (InvalidPublishedWebsiteSnapshot) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
