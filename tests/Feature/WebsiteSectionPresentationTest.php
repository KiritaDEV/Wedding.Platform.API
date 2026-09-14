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
