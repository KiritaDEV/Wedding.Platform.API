<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\CreateWebsiteSection;
use App\Actions\Websites\PublishWebsite;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AtomicWebsiteDraftSaveTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Website
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => 'Frozen Event']);
        $project = $this->initializeWebsite($event);
        $this->actingAs($owner)->withHeader('Accept', 'application/json');

        return $project->fresh();
    }

    private function url(Website $project): string
    {
        return "/api/events/{$project->event_id}/websites/{$project->id}";
    }

    private function save(Website $project, array $changes, ?string $revision = null): TestResponse
    {
        return $this->patchJson($this->url($project).'/draft', $changes, ['X-Website-Draft-Revision' => $revision ?? $project->fresh()->draft_revision]);
    }

    private function state(Website $project): array
    {
        return [$project->fresh()->getAttributes(), $project->sections()->get()->map->getAttributes()->all(), $project->event->fresh()->published_website_id];
    }

    public function test_atomic_save_updates_order_enabled_content_appearance_and_design_and_retains_omitted_sections(): void
    {
        $project = $this->project();
        $blank = app(CreateWebsiteSection::class)->handle($project, 'blank');
        $hero = $project->sections()->where('type', 'hero')->firstOrFail();
        $omitted = $project->sections()->where('type', 'rsvp')->firstOrFail();
        $before = $omitted->getAttributes();
        $content = $hero->content;
        $content['compositions']['shared']['childFlow']['elements'][0]['document']['children'][0]['children'][0]['text'] = 'Saved heading';
        $appearance = $hero->appearance;
        $appearance['shared']['backgroundTreatment'] = 'custom';
        $appearance['shared']['decorativeAppearance'] = ['background' => ['texture' => 'grain', 'textureStrength' => 45]];
        $design = $project->fresh()->design_settings;
        $design['fontSet'] = 'editorial';
        $ids = $project->sections()->pluck('id')->reverse()->values()->all();
        $token = $project->fresh()->draft_revision;
        $response = $this->save($project, ['sectionIds' => $ids, 'sections' => [
            ['id' => $hero->id, 'content' => $content, 'appearance' => $appearance],
            ['id' => $blank->id, 'isEnabled' => false],
        ], 'designSettings' => $design])->assertOk();
        $this->assertNotSame($token, $response->json('data.draftRevision'));
        $this->assertSame($project->fresh()->draft_revision, $response->json('data.draftRevision'));
        $this->assertSame($ids, array_column($response->json('data.sections'), 'id'));
        $this->assertSame($content, $hero->fresh()->content);
        $this->assertSame('grain', $hero->fresh()->appearance['shared']['decorativeAppearance']['background']['texture']);
        $this->assertFalse($blank->fresh()->is_enabled);
        $this->assertSame($before['content'], $omitted->fresh()->getRawOriginal('content'));
        $this->assertSame('editorial', $project->fresh()->design_settings['fontSet']);
    }

    public function test_late_validation_failure_rolls_back_every_write_and_revision(): void
    {
        $project = $this->project();
        $blank = app(CreateWebsiteSection::class)->handle($project, 'blank');
        $before = $this->state($project);
        $this->save($project, ['sections' => [['id' => $blank->id, 'isEnabled' => false]], 'designSettings' => ['colorTheme' => 'invalid']])->assertUnprocessable()->assertJsonValidationErrors('designSettings');
        $this->assertSame($before, $this->state($project));
    }

    public function test_invalid_or_foreign_media_rolls_back_order_and_content(): void
    {
        $project = $this->project();
        $hero = $project->sections()->where('type', 'hero')->firstOrFail();
        $content = $hero->content;
        $element = ['id' => 'missing-media', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [['id' => 'image', 'type' => 'image', 'mediaId' => '01M00000000000000000000000', 'alt' => 'Image']]];
        $content['compositions']['shared']['childFlow'] = ['elements' => [$element], 'order' => [['kind' => 'element', 'id' => $element['id']]]];
        $before = $this->state($project);
        $this->save($project, ['sectionIds' => $project->sections()->pluck('id')->reverse()->values()->all(), 'sections' => [['id' => $hero->id, 'content' => $content]]])->assertUnprocessable();
        $this->assertSame($before, $this->state($project));
        $foreign = MediaAsset::query()->create([
            'event_id' => Event::factory()->create()->id,
            'original_filename' => 'foreign.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg',
            'width' => 100, 'height' => 100, 'size_bytes' => 5,
            'content_hash' => hash('sha256', 'foreign'), 'storage_disk' => 'local', 'original_path' => 'not-written.jpg',
        ]);
        $content['compositions']['shared']['childFlow']['elements'][0]['items'][0]['mediaId'] = $foreign->id;
        $this->save($project, ['sectionIds' => $project->sections()->pluck('id')->reverse()->values()->all(), 'sections' => [['id' => $hero->id, 'content' => $content]]])->assertUnprocessable();
        $this->assertSame($before, $this->state($project));
    }

    public function test_two_tab_conflict_preserves_current_saved_draft_and_does_not_advance_revision(): void
    {
        $project = $this->project();
        $blank = app(CreateWebsiteSection::class)->handle($project, 'blank');
        $token = $this->getJson($this->url($project))->assertOk()->json('data.draftRevision');
        $this->save($project, ['sections' => [['id' => $blank->id, 'isEnabled' => false]]], $token)->assertOk();
        $before = $this->state($project);
        $this->save($project, ['sections' => [['id' => $blank->id, 'isEnabled' => true]]], $token)->assertConflict()->assertJsonPath('code', 'stale_website_draft')->assertJsonPath('currentDraftRevision', $project->fresh()->draft_revision);
        $this->assertSame($before, $this->state($project));
    }

    public function test_rapid_successive_saves_have_distinct_revisions_and_get_returns_committed_revision(): void
    {
        $project = $this->project();
        $blank = app(CreateWebsiteSection::class)->handle($project, 'blank');
        $tokens = [$project->fresh()->draft_revision];
        foreach ([false, true, false] as $enabled) {
            $tokens[] = $this->save($project, ['sections' => [['id' => $blank->id, 'isEnabled' => $enabled]]], end($tokens))->assertOk()->json('data.draftRevision');
        }
        $this->assertCount(4, array_unique($tokens));
        $this->getJson($this->url($project))->assertOk()->assertJsonPath('data.draftRevision', end($tokens));
    }

    public function test_missing_revision_cannot_bypass_the_boundary_on_aggregate_or_existing_routes(): void
    {
        $project = $this->project();
        $before = $this->state($project);
        $this->json('HEAD', $this->url($project))->assertOk();
        $this->assertSame($before, $this->state($project));
        $this->patchJson($this->url($project).'/draft', [])->assertStatus(428);
        $this->postJson($this->url($project).'/sections', ['type' => 'blank'])->assertStatus(428);
        $this->assertSame($before, $this->state($project));
    }

    public function test_existing_mutations_advance_tokens_and_reject_stale_payloads(): void
    {
        $project = $this->project();
        $old = $project->draft_revision;
        $response = $this->postJson($this->url($project).'/sections', ['type' => 'blank'], ['X-Website-Draft-Revision' => $old])->assertCreated();
        $new = $response->json('data.draftRevision');
        $this->assertNotSame($old, $new);
        $blank = collect($response->json('data.sections'))->firstWhere('type', 'blank');
        $this->putJson($this->url($project)."/sections/{$blank['id']}/enabled", ['isEnabled' => false], ['X-Website-Draft-Revision' => $old])->assertConflict();
        $this->putJson($this->url($project)."/sections/{$blank['id']}/enabled", ['isEnabled' => false], ['X-Website-Draft-Revision' => $new])->assertOk();
        $this->assertNotSame($new, $project->fresh()->draft_revision);
        $before = $this->state($project);
        $this->putJson($this->url($project).'/design', ['designSettings' => []], ['X-Website-Draft-Revision' => $project->fresh()->draft_revision])->assertUnprocessable();
        $this->assertSame($before, $this->state($project));
    }

    public function test_omitted_or_missing_section_and_foreign_project_are_not_replaced(): void
    {
        $project = $this->project();
        $before = $this->state($project);
        $this->save($project, ['sections' => [['id' => 'deleted-section', 'isEnabled' => false]]])->assertUnprocessable()->assertJsonValidationErrors('sections.0.id');
        $this->save($project, ['sectionIds' => []])->assertUnprocessable();
        $this->save($project, ['published_snapshot' => []])->assertUnprocessable();
        $this->assertSame($before, $this->state($project));
        $other = $this->project();
        $this->patchJson("/api/events/{$other->event_id}/websites/{$project->id}/draft", [])->assertNotFound();
        $this->save($project, [])->assertForbidden();
    }

    public function test_save_keeps_snapshot_timestamp_pointer_and_public_presentation_until_republish(): void
    {
        $project = $this->project();
        $initialRevision = $project->draft_revision;
        app(PublishWebsite::class)->handle($project->event, $project);
        $this->assertSame($initialRevision, $project->fresh()->draft_revision);
        $before = $project->fresh();
        $hero = $project->sections()->where('type', 'hero')->firstOrFail();
        $content = $hero->content;
        $content['compositions']['shared']['childFlow']['elements'][0]['document']['children'][0]['children'][0]['text'] = 'Saved later';
        $this->save($project, ['sections' => [['id' => $hero->id, 'content' => $content]]])->assertOk();
        $after = $project->fresh();
        $this->assertSame($before->getRawOriginal('published_snapshot'), $after->getRawOriginal('published_snapshot'));
        $this->assertSame($before->getRawOriginal('snapshot_published_at'), $after->getRawOriginal('snapshot_published_at'));
        $this->assertSame($project->id, $project->event->fresh()->published_website_id);
        $this->assertStringNotContainsString('Saved later', $after->getRawOriginal('published_snapshot'));
        app(PublishWebsite::class)->handle($project->event, $project);
        $this->assertSame($after->draft_revision, $project->fresh()->draft_revision);
        $this->assertStringContainsString('Saved later', $project->fresh()->getRawOriginal('published_snapshot'));
    }

    public function test_nested_groups_hidden_blocks_and_all_device_branches_retain_identity_and_appearance(): void
    {
        $project = $this->project();
        $blank = app(CreateWebsiteSection::class)->handle($project, 'blank');
        $branch = fn (string $device): array => ['childFlow' => [
            'elements' => [['id' => $device.'-outer', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'isHidden' => true, 'children' => [
                ['id' => $device.'-inner', 'type' => 'compositionGroup', 'editorName' => 'Group 2', 'children' => [
                    ['id' => $device.'-text', 'type' => 'text', 'editorName' => 'Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => $device]]]]]],
                ]],
            ]]],
            'order' => [['kind' => 'element', 'id' => $device.'-outer']],
        ]];
        $content = ['semantic' => [], 'compositions' => ['shared' => $branch('shared'), 'custom' => []]];
        $appearance = $blank->appearance;
        foreach (['desktop', 'tablet', 'mobile'] as $device) {
            $content['compositions']['custom'][$device] = $branch($device);
            $appearance['custom'][$device] = [...$appearance['shared'], 'backgroundTreatment' => 'custom', 'decorativeAppearance' => ['background' => ['texture' => 'grain', 'textureStrength' => $device === 'mobile' ? 25 : 75]]];
        }
        $this->save($project, ['sections' => [['id' => $blank->id, 'content' => $content, 'appearance' => $appearance, 'isEnabled' => false]]])->assertOk();
        $this->assertSame($content, $blank->fresh()->content);
        $this->assertSame($appearance, $blank->fresh()->appearance);
        $this->save($project, ['sections' => [['id' => $blank->id, 'isEnabled' => true]]])->assertOk();
        $this->assertSame($content, $blank->fresh()->content);
        $this->assertSame($appearance, $blank->fresh()->appearance);
    }

    public function test_unpaired_content_and_malformed_json_objects_are_rejected_without_writes(): void
    {
        $project = $this->project();
        $blank = app(CreateWebsiteSection::class)->handle($project, 'blank');
        $before = $this->state($project);
        $content = $blank->content;
        $content['compositions']['custom']['mobile'] = $content['compositions']['shared'];
        $this->save($project, ['sections' => [['id' => $blank->id, 'content' => $content]]])->assertUnprocessable();
        $content = $blank->content;
        $content['compositions']['shared']['childFlow'] = ['elements' => [['id' => 'divider', 'type' => 'divider', 'editorName' => 'Divider 1', 'appearance' => []]], 'order' => [['kind' => 'element', 'id' => 'divider']]];
        $this->save($project, ['sections' => [['id' => $blank->id, 'content' => $content]]])->assertUnprocessable()->assertJsonValidationErrors('sections.0.content.compositions.shared.childFlow.elements.0.appearance');
        $this->assertSame($before, $this->state($project));
    }

    public function test_editor_fixture_has_canonical_saved_state_and_revision(): void
    {
        $project = $this->project();
        $response = $this->getJson($this->url($project))->assertOk()->assertJsonPath('data.draftRevision', $project->draft_revision);
        if ($path = getenv('DRAFT_SAVE_FIXTURE_PATH')) {
            file_put_contents($path, $response->getContent());
        }
    }

    public function test_all_existing_project_mutation_routes_and_legacy_routes_participate_in_revision_control(): void
    {
        $project = $this->project();
        $path = $this->url($project);
        $mutate = function (string $method, string $route, array $data = []) use ($project): array {
            $old = $project->fresh()->draft_revision;
            $response = $this->json($method, $route, $data, ['X-Website-Draft-Revision' => $old]);
            $this->assertTrue(in_array($response->status(), [200, 201], true), $response->getContent());
            $new = $response->json('data.draftRevision');
            $this->assertNotSame($old, $new, $route);
            $this->assertSame($new, $project->fresh()->draft_revision);
            $this->save($project, ['designSettings' => $project->fresh()->design_settings], $old)->assertConflict();

            return $response->json('data');
        };
        $created = $mutate('POST', $path.'/sections', ['type' => 'blank']);
        $blank = collect($created['sections'])->firstWhere('type', 'blank');
        $sectionPath = $path.'/sections/'.$blank['id'];
        $mutate('PUT', $sectionPath.'/editor-name', ['editorName' => 'Updated name']);
        $mutate('PUT', $sectionPath.'/enabled', ['isEnabled' => false]);
        $mutate('PUT', $sectionPath, ['content' => $blank['content']]);
        $mutate('PUT', $sectionPath.'/appearance', ['appearance' => $blank['appearance']]);
        $mutate('PUT', $sectionPath.'/presentation', ['content' => $blank['content'], 'appearance' => $blank['appearance']]);
        $mutate('POST', $sectionPath.'/duplicate');
        $mutate('PUT', $path.'/sections/order', ['sectionIds' => $project->sections()->pluck('id')->reverse()->values()->all()]);
        $mutate('PUT', $path.'/design', ['designSettings' => $project->fresh()->design_settings]);
        $mutate('POST', $path.'/colors', ['value' => '#123456']);
        $gallery = $project->sections()->where('type', 'gallery')->firstOrFail();
        $mutate('PUT', $path."/sections/{$gallery->id}/design-defaults", ['designDefaults' => []]);
        $mutate('DELETE', $sectionPath);
        $mutate('PUT', "/api/events/{$project->event_id}/website/sections/{$gallery->id}/enabled", ['isEnabled' => false]);
    }

    public function test_unexpected_storage_failure_after_section_write_rolls_back_the_entire_save(): void
    {
        $project = $this->project();
        $blank = app(CreateWebsiteSection::class)->handle($project, 'blank');
        $before = $this->state($project);
        $options = $this->getJson($this->url($project))->assertOk()->json('data.template.designOptions.colorThemes');
        $settings = $project->fresh()->design_settings;
        $settings['colorTheme'] = collect($options)->first(fn (array $option): bool => $option['key'] !== $settings['colorTheme'])['key'];
        $dispatcher = Website::getEventDispatcher();
        Website::setEventDispatcher(clone $dispatcher);
        Website::saving(function (Website $website): void {
            if ($website->isDirty('design_settings')) {
                throw new \RuntimeException('Injected database failure.');
            }
        });
        try {
            $this->save($project, ['sections' => [['id' => $blank->id, 'isEnabled' => false]], 'designSettings' => $settings])->assertStatus(500);
            $this->assertSame($before, $this->state($project));
        } finally {
            Website::setEventDispatcher($dispatcher);
        }
    }

    public function test_object_in_place_of_a_section_list_is_rejected_without_a_server_error_or_writes(): void
    {
        $project = $this->project();
        $hero = $project->sections()->where('type', 'hero')->firstOrFail();
        $before = $this->state($project);
        $this->save($project, ['sections' => (object) ['0' => ['id' => $hero->id, 'content' => $hero->content]]])->assertUnprocessable()->assertJsonValidationErrors('sections');
        $this->save($project, [])->assertUnprocessable()->assertJsonValidationErrors('draft');
        $this->assertSame($before, $this->state($project));
    }
}
