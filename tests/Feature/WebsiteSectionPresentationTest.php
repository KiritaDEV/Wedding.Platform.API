<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\InitializeEventWebsite;
use App\Models\User;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteSectionPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_gallery_layout_round_trips_each_owner_and_sparse_resets_without_semantic_changes(): void
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => 'Gallery Layout']);
        $website = app(InitializeEventWebsite::class)->handle($event, WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $gallery = $website->sections()->where('type', 'gallery')->sole();
        $content = $gallery->content;
        $semantic = $content['semantic'];
        $appearance = $gallery->appearance;
        $appearance['shared'] = [...$appearance['shared'], 'columns' => 3, 'gap' => 'medium', 'aspectRatio' => 'portrait'];
        foreach (['desktop' => [6, 'large', 'landscape'], 'tablet' => [2, 'small', 'square'], 'mobile' => [1, 'large', 'landscape']] as $device => [$columns, $gap, $aspectRatio]) {
            $content['compositions']['custom'][$device] = ['childFlow' => ['elements' => [], 'order' => [['kind' => 'specialized', 'key' => 'content']]]];
            $appearance['custom'][$device] = [...$gallery->appearance['shared'], 'columns' => $columns, 'gap' => $gap, 'aspectRatio' => $aspectRatio];
        }
        $url = "/api/events/{$event->id}/websites/{$website->id}/sections/{$gallery->id}";
        $this->actingAs($owner)->putJson("{$url}/presentation", compact('content', 'appearance'))->assertOk();
        $this->assertSame($appearance, $gallery->refresh()->appearance);
        $this->assertSame($semantic, $gallery->content['semantic']);

        foreach (['columns', 'gap', 'aspectRatio'] as $key) {
            unset($appearance['custom']['tablet'][$key]);
            $this->actingAs($owner)->putJson("{$url}/appearance", compact('appearance'))->assertOk();
            $this->assertArrayNotHasKey($key, $gallery->refresh()->appearance['custom']['tablet']);
            $this->assertSame($semantic, $gallery->content['semantic']);
        }
        unset($content['compositions']['custom']['mobile'], $appearance['custom']['mobile']);
        $this->actingAs($owner)->putJson("{$url}/presentation", compact('content', 'appearance'))->assertOk();
        $this->assertSame($appearance, $gallery->refresh()->appearance);
        $this->assertSame($content['compositions'], $gallery->content['compositions']);
        $this->assertSame($semantic, $gallery->content['semantic']);
        $reloaded = collect($this->actingAs($owner)->getJson("/api/events/{$event->id}/websites/{$website->id}")->assertOk()->json('data.sections'))->firstWhere('id', $gallery->id);
        $this->assertSame($appearance, $reloaded['appearance']);
        $this->assertSame($semantic, $reloaded['content']['semantic']);
        $this->assertSame($content['compositions'], $reloaded['content']['compositions']);
    }

    public function test_customize_and_reset_persist_paired_composition_and_appearance_atomically(): void
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => 'Neil & Hazel']);
        $website = app(InitializeEventWebsite::class)->handle($event, WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $hero = $website->sections()->where('type', 'hero')->sole();
        $content = $hero->content;
        $appearance = $hero->appearance;
        $content['compositions']['custom']['mobile'] = ['childFlow' => ['elements' => [], 'order' => []]];
        $appearance['custom']['mobile'] = [...$appearance['shared'], 'backgroundTreatment' => 'custom', 'decorativeAppearance' => ['background' => ['texture' => 'grain', 'textureStrength' => 45, 'pattern' => 'botanical', 'patternStrength' => 70, 'overlay' => 'soft']]];
        $url = "/api/events/{$event->id}/websites/{$website->id}/sections/{$hero->id}/presentation";

        $this->actingAs($owner)->putJson($url, compact('content', 'appearance'))->assertOk();
        $stored = $hero->refresh();
        $this->assertArrayHasKey('mobile', $stored->content['compositions']['custom']);
        $this->assertSame('grain', $stored->appearance['custom']['mobile']['decorativeAppearance']['background']['texture']);
        $this->assertSame('inherit', $stored->appearance['shared']['backgroundTreatment']);

        unset($content['compositions']['custom'], $appearance['custom']);
        $this->actingAs($owner)->putJson($url, compact('content', 'appearance'))->assertOk();
        $this->assertArrayNotHasKey('custom', $hero->refresh()->appearance);
    }

    public function test_orphan_composition_or_appearance_is_rejected(): void
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => 'Neil & Hazel']);
        $website = app(InitializeEventWebsite::class)->handle($event, WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $hero = $website->sections()->where('type', 'hero')->sole();
        $appearance = $hero->appearance;
        $appearance['custom']['mobile'] = $appearance['shared'];

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/websites/{$website->id}/sections/{$hero->id}/appearance", compact('appearance'))->assertUnprocessable();
        $this->assertArrayNotHasKey('custom', $hero->refresh()->appearance);
    }

    public function test_customize_canonicalizes_live_editor_text_runs_before_validation(): void
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => 'Neil & Hazel']);
        $website = app(InitializeEventWebsite::class)->handle($event, WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $hero = $website->sections()->where('type', 'hero')->sole();
        $content = $hero->content;
        $appearance = $hero->appearance;
        $custom = $content['compositions']['shared'];
        foreach ($custom['childFlow']['elements'] as &$element) {
            $element['id'] .= '-tablet';
            if (($element['type'] ?? null) === 'text') {
                $element['document']['children'][0]['children'][0]['editorMetadata'] = true;
                $element['document']['children'][0]['children'][0]['marks'] = ['bold' => true, 'italic' => false];
            }
        }
        $custom['childFlow']['elements'][0]['document']['children'][] = [
            'type' => 'paragraph',
            'children' => [['text' => '']],
        ];
        unset($element);
        foreach ($custom['childFlow']['order'] as &$reference) {
            if (($reference['kind'] ?? null) === 'element') {
                $reference['id'] .= '-tablet';
            }
        }
        unset($reference);
        $content['compositions']['custom']['tablet'] = $custom;
        $appearance['custom']['tablet'] = $appearance['shared'];

        $url = "/api/events/{$event->id}/websites/{$website->id}/sections/{$hero->id}/presentation";
        $this->actingAs($owner)->putJson($url, compact('content', 'appearance'))->assertOk();

        $runs = collect($hero->refresh()->content['compositions']['custom']['tablet']['childFlow']['elements'])
            ->where('type', 'text')->pluck('document.children.0.children.0');
        $this->assertNotEmpty($runs);
        $runs->each(function (array $run): void {
            $this->assertArrayNotHasKey('editorMetadata', $run);
            $this->assertSame(['bold' => true], $run['marks']);
        });
        $this->assertSame('', $hero->content['compositions']['custom']['tablet']['childFlow']['elements'][0]['document']['children'][1]['children'][0]['text']);
    }
}
