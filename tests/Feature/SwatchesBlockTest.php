<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Invitations\CreateInvitation;
use App\Actions\Websites\PublishWebsite;
use App\Models\User;
use App\Website\Elements\WebsiteElementIdentityRegenerator;
use App\Website\Publishing\InvalidPublishedWebsiteSnapshot;
use App\Website\Publishing\PublishedWebsiteSnapshotBuilder;
use App\Website\Publishing\PublishedWebsiteSnapshotReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SwatchesBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_surface_save_publish_legacy_snapshots_and_independent_custom_owners(): void
    {
        foreach (['classic-filipiniana-v1', 'modern-editorial-v1'] as $template) {
            $owner = User::factory()->create();
            $event = app(CreateEvent::class)->handle($owner, ['name' => 'Palette surfaces']);
            $website = $this->initializeWebsite($event, $template);
            $section = $website->sections()->where('type', 'hero')->sole();
            $palette = ['id' => 'palette', 'type' => 'swatches', 'editorName' => 'Swatches', 'items' => [['id' => 'a', 'color' => '#F8FAFC', 'label' => 'Cloud'], ['id' => 'b', 'color' => '#123456']], 'appearance' => ['shape' => 'roundedSquare', 'size' => '3xl', 'showLabels' => true, 'showHex' => true, 'captionStyle' => 'soft']];
            $group = ['id' => 'g', 'type' => 'compositionGroup', 'editorName' => 'Group', 'children' => [$palette]];
            $content = ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => ['elements' => [$group], 'order' => [['kind' => 'element', 'id' => 'g']]]]]];
            $path = "/api/events/{$event->id}/websites/{$website->id}/sections/{$section->id}";
            $this->actingAs($owner)->draftPutJson($path, ['content' => $content])->assertOk();
            $this->assertSame($content, $section->refresh()->content);
            app(PublishWebsite::class)->handle($event, $website);
            $legacy = json_encode($website->refresh()->published_snapshot);
            $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Guest']]);
            $token = $invitation->currentPrivateLink->encrypted_token;
            $find = fn ($site) => collect($site['sections'])->firstWhere('id', $section->id)['content']['compositions']['shared']['childFlow']['elements'][0]['children'][0];
            $previous = $palette;
            foreach (['flat', 'raised', 'inset'] as $surface) {
                $content['compositions']['shared']['childFlow']['elements'][0]['children'][0]['appearance']['surfaceStyle'] = $surface;
                $this->draftPutJson($path, ['content' => $content])->assertOk();
                $this->assertSame($content, $section->refresh()->content);
                $this->assertSame($previous, $find($this->getJson('/api/public/events/'.$event->slug.'/site')->assertOk()->json('data.website')));
                $this->assertSame($previous, $find($this->draftPostJson('/api/private-invitations/site', ['token' => $token])->assertOk()->json('data.website')));
                app(PublishWebsite::class)->handle($event, $website);
                $previous = $content['compositions']['shared']['childFlow']['elements'][0]['children'][0];
                $this->assertSame($previous, $find($this->getJson('/api/public/events/'.$event->slug.'/site')->assertOk()->json('data.website')));
                $this->assertSame($previous, $find($this->draftPostJson('/api/private-invitations/site', ['token' => $token])->assertOk()->json('data.website')));
                app(PublishedWebsiteSnapshotReader::class)->decode($legacy);
            }
            foreach (['desktop', 'tablet', 'mobile'] as $viewport) {
                $content['compositions']['custom'][$viewport] = app(WebsiteElementIdentityRegenerator::class)->regenerateComposition($content['compositions']['shared']);
                $content['compositions']['custom'][$viewport]['childFlow']['elements'][0]['children'][0]['appearance']['surfaceStyle'] = 'raised';
            }
            $appearance = $section->appearance;
            foreach (['desktop', 'tablet', 'mobile'] as $viewport) {
                $appearance['custom'][$viewport] = $appearance['shared'];
            }
            $this->draftMutation('PATCH', "/api/events/{$event->id}/websites/{$website->id}/draft", ['sections' => [['id' => $section->id, 'content' => $content, 'appearance' => $appearance]]])->assertOk();
            $this->assertSame($content, $section->refresh()->content);
            app(PublishedWebsiteSnapshotReader::class)->decode(app(PublishedWebsiteSnapshotBuilder::class)->build($website)->toJson());
            foreach (['bevel', '', null, 1, false, [], new \stdClass] as $invalid) {
                $bad = $content;
                $bad['compositions']['shared']['childFlow']['elements'][0]['children'][0]['appearance']['surfaceStyle'] = $invalid;
                $this->draftPutJson($path, ['content' => $bad])->assertUnprocessable();
                $this->assertSame($content, $section->refresh()->content);
            }
        }
    }

    public function test_shape_radius_sizes_save_publish_and_owner_isolation(): void
    {
        foreach (['classic-filipiniana-v1', 'modern-editorial-v1'] as $template) {
            $owner = User::factory()->create();
            $event = app(CreateEvent::class)->handle($owner, ['name' => 'Swatch geometry']);
            $website = $this->initializeWebsite($event, $template);
            $section = $website->sections()->where('type', 'hero')->sole();
            $palette = ['id' => 'palette', 'type' => 'swatches', 'editorName' => 'Swatches', 'items' => [['id' => 'a', 'color' => '#FFFFFF', 'label' => 'Cloud']], 'appearance' => ['shape' => 'roundedSquare', 'showLabels' => true]];
            $content = ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => ['elements' => [$palette], 'order' => [['kind' => 'element', 'id' => 'palette']]]]]];
            $path = "/api/events/{$event->id}/websites/{$website->id}/sections/{$section->id}";
            $this->actingAs($owner)->draftPutJson($path, ['content' => $content])->assertOk();
            $this->assertSame($content, $section->refresh()->content);
            app(PublishWebsite::class)->handle($event, $website);
            $legacySnapshot = json_encode($website->refresh()->published_snapshot);
            $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Guest']]);
            $token = $invitation->currentPrivateLink->encrypted_token;
            $find = fn ($site) => collect($site['sections'])->firstWhere('id', $section->id)['content']['compositions']['shared']['childFlow']['elements'][0];
            $previous = $palette;
            foreach (['small', 'medium', 'large', 'xl', '2xl', '3xl'] as $index => $size) {
                $appearance = ['shape' => ['circle', 'square', 'rectangle'][$index % 3], 'cornerRadius' => ['none', 'small', 'medium', 'large', 'full', 'none'][$index], 'size' => $size, 'showLabels' => true, 'showHex' => true, 'captionStyle' => $index % 2 ? 'plain' : 'soft'];
                $content['compositions']['shared']['childFlow']['elements'][0]['appearance'] = $appearance;
                $this->draftPutJson($path, ['content' => $content])->assertOk();
                $this->assertSame($content, $section->refresh()->content);
                $this->assertSame($previous, $find($this->getJson('/api/public/events/'.$event->slug.'/site')->assertOk()->json('data.website')));
                app(PublishWebsite::class)->handle($event, $website);
                $previous = $content['compositions']['shared']['childFlow']['elements'][0];
                $this->assertSame($previous, $find($this->getJson('/api/public/events/'.$event->slug.'/site')->assertOk()->json('data.website')));
                $this->assertSame($previous, $find($this->draftPostJson('/api/private-invitations/site', ['token' => $token])->assertOk()->json('data.website')));
                app(PublishedWebsiteSnapshotReader::class)->decode($legacySnapshot);
            }
            $content['compositions']['custom']['mobile'] = app(WebsiteElementIdentityRegenerator::class)->regenerateComposition($content['compositions']['shared']);
            $content['compositions']['custom']['mobile']['childFlow']['elements'][0]['appearance'] = ['shape' => 'square', 'cornerRadius' => 'full', 'size' => 'xl'];
            $sectionAppearance = $section->appearance;
            $sectionAppearance['custom']['mobile'] = $sectionAppearance['shared'];
            $this->draftMutation('PATCH', "/api/events/{$event->id}/websites/{$website->id}/draft", ['sections' => [['id' => $section->id, 'content' => $content, 'appearance' => $sectionAppearance]]])->assertOk();
            $this->assertSame($content, $section->refresh()->content);
            app(PublishedWebsiteSnapshotReader::class)->decode(app(PublishedWebsiteSnapshotBuilder::class)->build($website)->toJson());
            foreach (['shape' => 'oval', 'cornerRadius' => '8px', 'size' => '4xl'] as $field => $value) {
                $bad = $content;
                $bad['compositions']['shared']['childFlow']['elements'][0]['appearance'][$field] = $value;
                $this->draftPutJson($path, ['content' => $bad])->assertUnprocessable();
                $this->assertSame($content, $section->refresh()->content);
            }
        }
    }

    public function test_caption_enhancement_round_trip_legacy_snapshot_and_independent_mobile(): void
    {
        foreach (['classic-filipiniana-v1', 'modern-editorial-v1'] as $template) {
            $owner = User::factory()->create();
            $event = app(CreateEvent::class)->handle($owner, ['name' => 'Caption styles']);
            $website = $this->initializeWebsite($event, $template);
            $section = $website->sections()->where('type', 'hero')->sole();
            $palette = ['id' => 'palette', 'type' => 'swatches', 'editorName' => 'Swatches', 'items' => [['id' => 'color-a', 'color' => '#FFFFFF', 'label' => 'Cloud'], ['id' => 'color-b', 'color' => '#000000', 'label' => 'Ink']], 'appearance' => ['showLabels' => true, 'showHex' => true]];
            $content = ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => ['elements' => [$palette], 'order' => [['kind' => 'element', 'id' => 'palette']]]]]];
            $path = "/api/events/{$event->id}/websites/{$website->id}/sections/{$section->id}";
            $this->actingAs($owner)->draftPutJson($path, ['content' => $content])->assertOk();
            app(PublishWebsite::class)->handle($event, $website);
            $legacySnapshot = json_encode($website->refresh()->published_snapshot);
            $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Guest']]);
            $token = $invitation->currentPrivateLink->encrypted_token;
            $find = fn ($site) => collect($site['sections'])->firstWhere('id', $section->id)['content']['compositions']['shared']['childFlow']['elements'][0];
            $this->assertSame($palette, $find($this->getJson('/api/public/events/'.$event->slug.'/site')->assertOk()->json('data.website')));
            $previousGuest = $palette;
            foreach (['plain', 'soft'] as $style) {
                $content['compositions']['shared']['childFlow']['elements'][0]['appearance'] = [...$palette['appearance'], 'shape' => 'rectangle', 'captionStyle' => $style, 'captionTextColor' => '#aabbcc', 'captionBackgroundColor' => '#ddeeff'];
                $content['compositions']['shared']['childFlow']['elements'][0]['items'] = array_reverse($palette['items']);
                $this->draftPutJson($path, ['content' => $content])->assertOk();
                $saved = $section->refresh()->content;
                $expected = $saved['compositions']['shared']['childFlow']['elements'][0];
                $this->assertSame('#AABBCC', $expected['appearance']['captionTextColor']);
                $this->assertSame('#DDEEFF', $expected['appearance']['captionBackgroundColor']);
                $this->assertSame('Ink', $expected['items'][0]['label']);
                $this->assertSame($previousGuest, $find($this->getJson('/api/public/events/'.$event->slug.'/site')->assertOk()->json('data.website')));
                $this->assertSame($previousGuest, $find($this->draftPostJson('/api/private-invitations/site', ['token' => $token])->assertOk()->json('data.website')));
                app(PublishWebsite::class)->handle($event, $website);
                $this->assertSame($expected, $find($this->getJson('/api/public/events/'.$event->slug.'/site')->assertOk()->json('data.website')));
                $this->assertSame($expected, $find($this->draftPostJson('/api/private-invitations/site', ['token' => $token])->assertOk()->json('data.website')));
                $previousGuest = $expected;
                app(PublishedWebsiteSnapshotReader::class)->decode($legacySnapshot);
                foreach ([['captionStyle' => 'chip'], ['captionTextColor' => 'red'], ['captionBackgroundColor' => '#ffffff80'], ['captionTextColor' => null]] as $badFields) {
                    $bad = $saved;
                    $bad['compositions']['shared']['childFlow']['elements'][0]['appearance'] = array_replace($expected['appearance'], $badFields);
                    $this->draftPutJson($path, ['content' => $bad])->assertUnprocessable();
                    $this->assertSame($saved, $section->refresh()->content);
                }
            }
            $saved['compositions']['custom']['mobile'] = app(WebsiteElementIdentityRegenerator::class)->regenerateComposition($saved['compositions']['shared']);
            $saved['compositions']['custom']['mobile']['childFlow']['elements'][0]['appearance']['captionStyle'] = 'plain';
            $saved['compositions']['custom']['mobile']['childFlow']['elements'][0]['appearance']['captionTextColor'] = '#123456';
            $appearance = $section->appearance;
            $appearance['custom']['mobile'] = $appearance['shared'];
            $this->draftMutation('PATCH', "/api/events/{$event->id}/websites/{$website->id}/draft", ['sections' => [['id' => $section->id, 'content' => $saved, 'appearance' => $appearance]]])->assertOk();
            $saved = $section->refresh()->content;
            $this->assertSame('soft', $saved['compositions']['shared']['childFlow']['elements'][0]['appearance']['captionStyle']);
            $this->assertSame('#AABBCC', $saved['compositions']['shared']['childFlow']['elements'][0]['appearance']['captionTextColor']);
            $this->assertSame('#123456', $saved['compositions']['custom']['mobile']['childFlow']['elements'][0]['appearance']['captionTextColor']);
            app(PublishedWebsiteSnapshotReader::class)->decode(app(PublishedWebsiteSnapshotBuilder::class)->build($website)->toJson());
        }
    }

    public function test_save_reload_publish_guest_isolation_and_json_shapes_for_both_templates(): void
    {
        foreach (['classic-filipiniana-v1', 'modern-editorial-v1'] as $template) {
            $owner = User::factory()->create();
            $event = app(CreateEvent::class)->handle($owner, ['name' => 'Color palette']);
            $website = $this->initializeWebsite($event, $template);
            $section = $website->sections()->where('type', 'hero')->sole();
            // Capture a historical, Swatches-free snapshot before authoring new content.
            $historicalTree = json_decode(app(PublishedWebsiteSnapshotBuilder::class)->build($website)->toJson());
            $capabilities = $historicalTree->website->template->capabilities;
            $capabilities->elements = array_values(array_filter($capabilities->elements, fn ($type) => $type !== 'swatches'));
            $capabilities->elementCapabilities = array_values(array_filter($capabilities->elementCapabilities, fn ($entry) => $entry->type !== 'swatches'));
            foreach ($capabilities->sections as $entry) {
                if (is_array($entry->allowedElementTypes ?? null)) {
                    $entry->allowedElementTypes = array_values(array_filter($entry->allowedElementTypes, fn ($type) => $type !== 'swatches'));
                }
            }
            $historical = json_encode($historicalTree);
            $palette = ['id' => 'palette', 'type' => 'swatches', 'editorName' => 'Swatches 1', 'items' => [['id' => 'a', 'color' => '#aabbcc', 'label' => ' Slate '], ['id' => 'b', 'color' => '#AABBCC']], 'appearance' => ['showLabels' => true, 'showHex' => true, 'captionTypography' => ['fontFamilyId' => 'inter', 'fontSize' => 's'], 'outerSpacing' => ['top' => 'm']]];
            $group = ['id' => 'g', 'type' => 'compositionGroup', 'editorName' => 'Group', 'children' => [$palette]];
            $shared = ['childFlow' => ['elements' => [$group], 'order' => [['kind' => 'element', 'id' => 'g']]]];
            $mobile = app(WebsiteElementIdentityRegenerator::class)->regenerateComposition($shared);
            $mobile['childFlow']['elements'][0]['children'][0]['items'][0]['color'] = '#000000';
            $content = ['semantic' => [], 'compositions' => ['shared' => $shared, 'custom' => ['mobile' => $mobile]]];
            $appearance = $section->appearance;
            $appearance['custom']['mobile'] = $appearance['shared'];
            $path = "/api/events/{$event->id}/websites/{$website->id}/sections/{$section->id}";
            $this->actingAs($owner)->draftMutation('PATCH', "/api/events/{$event->id}/websites/{$website->id}/draft", ['sections' => [['id' => $section->id, 'content' => $content, 'appearance' => $appearance]]])->assertOk();
            $saved = $section->refresh()->content;
            $stored = $saved['compositions']['shared']['childFlow']['elements'][0]['children'][0];
            $this->assertSame('#AABBCC', $stored['items'][0]['color']);
            $this->assertSame('Slate', $stored['items'][0]['label']);
            $this->assertSame('#000000', $saved['compositions']['custom']['mobile']['childFlow']['elements'][0]['children'][0]['items'][0]['color']);
            app(PublishedWebsiteSnapshotReader::class)->decode($historical);
            app(PublishWebsite::class)->handle($event, $website);
            $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Guest']]);
            $token = $invitation->currentPrivateLink->encrypted_token;
            $find = fn ($site) => collect($site['sections'])->firstWhere('id', $section->id)['content']['compositions']['shared']['childFlow']['elements'][0]['children'][0];
            $this->assertSame($stored, $find($this->getJson('/api/public/events/'.$event->slug.'/site')->assertOk()->json('data.website')));
            $this->assertSame($stored, $find($this->draftPostJson('/api/private-invitations/site', ['token' => $token])->assertOk()->json('data.website')));
            $snapshot = json_encode($website->refresh()->published_snapshot);
            foreach ([['appearance' => []], ['appearance' => ['captionTypography' => []]], ['appearance' => ['animation' => ['entrance' => []]]], ['items' => (object) ['a' => ['id' => 'a', 'color' => '#000000']]], ['items' => [[]]], ['items' => [['id' => 'a', 'color' => '#000000', 'label' => null]]]] as $patch) {
                $bad = $saved;
                $bad['compositions']['shared']['childFlow']['elements'][0]['children'][0] = array_replace($stored, $patch);
                $this->draftPutJson($path, ['content' => $bad])->assertUnprocessable();
                $this->assertSame($saved, $section->refresh()->content);
            }
            $saved['compositions']['shared']['childFlow']['elements'][0]['children'][0]['items'][0]['color'] = '#FFFFFF';
            $this->draftPutJson($path, ['content' => $saved])->assertOk();
            $this->assertSame($snapshot, json_encode($website->refresh()->published_snapshot));
            $this->assertSame($stored, $find($this->getJson('/api/public/events/'.$event->slug.'/site')->assertOk()->json('data.website')));
            $this->assertSame($stored, $find($this->draftPostJson('/api/private-invitations/site', ['token' => $token])->assertOk()->json('data.website')));
            $badSnapshot = json_decode($snapshot);
            foreach ($badSnapshot->website->sections as $entry) {
                if ($entry->id === $section->id) {
                    $entry->content->compositions->shared->childFlow->elements[0]->children[0]->appearance = [];
                }
            }
            try {
                app(PublishedWebsiteSnapshotReader::class)->decode(json_encode($badSnapshot));
                $this->fail('Malformed snapshot accepted');
            } catch (InvalidPublishedWebsiteSnapshot) {
                $this->addToAssertionCount(1);
            }
            // Empty objects remain objects through save and publication.
            $saved['compositions']['shared']['childFlow']['elements'][0]['children'][0]['appearance'] = (object) ['captionTypography' => new \stdClass];
            $this->draftPutJson($path, ['content' => $saved])->assertOk();
            app(PublishedWebsiteSnapshotReader::class)->decode(app(PublishedWebsiteSnapshotBuilder::class)->build($website)->toJson());
            $saved['compositions']['shared']['childFlow']['elements'][0]['children'][0]['appearance'] = new \stdClass;
            $this->draftPutJson($path, ['content' => $saved])->assertOk();
            app(PublishedWebsiteSnapshotReader::class)->decode(app(PublishedWebsiteSnapshotBuilder::class)->build($website)->toJson());
        }
    }
}
