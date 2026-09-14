<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\AddWebsiteProjectColor;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DividerDraftApiTest extends TestCase
{
    use RefreshDatabase;

    private function blankSection(Website $website): WebsiteSection
    {
        return WebsiteSection::factory()->for($website)->forType('blank')->create([
            'sort_order' => 100,
            'editor_name' => 'Section 1',
            'content' => $this->content(['elements' => [], 'order' => []]),
        ]);
    }

    public static function depths(): array
    {
        return ['direct' => [0], 'Group child' => [1], 'nested Group child' => [2]];
    }

    #[DataProvider('depths')]
    public function test_complete_divider_matrix_round_trips_twice(int $depth): void
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => 'Divider round trip']);
        $website = $this->initializeWebsite($event);
        $website = app(AddWebsiteProjectColor::class)->handle($website, '#123ABC');
        $customId = $website->design_settings['customColors'][0]['id'];
        $section = $this->blankSection($website);
        $url = "/api/events/{$event->id}/websites/{$website->id}";
        $fixtures = json_decode(file_get_contents(__DIR__.'/../Fixtures/divider-contract.json'), true, flags: JSON_THROW_ON_ERROR);
        $exports = [];
        foreach ($fixtures['roundTripCases'] as $case) {
            $element = $case['element'];
            if (str_starts_with($element['appearance']['colorId'], 'project-color-')) {
                $element['appearance']['colorId'] = $customId;
            }
            for ($index = 0; $index < $depth; $index++) {
                $element = ['id' => 'group-'.$index, 'type' => 'compositionGroup', 'editorName' => 'Group '.($index + 1), 'children' => [$element]];
            }
            $content = $this->content(['elements' => [$element], 'order' => [['kind' => 'element', 'id' => $element['id']]]]);
            $firstResponse = $this->actingAs($owner)->putJson($url.'/sections/'.$section->id, ['content' => $content])->assertOk();
            $first = $firstResponse->json('data');
            $returned = collect($first['sections'])->firstWhere('id', $section->id)['content'];
            $this->assertSame($content, $returned);
            $this->assertSame($content, $section->refresh()->content);
            $secondResponse = $this->putJson($url.'/sections/'.$section->id, ['content' => $returned])->assertOk();
            $second = $secondResponse->json('data');
            $this->assertSame($content, collect($second['sections'])->firstWhere('id', $section->id)['content']);
            $this->assertSame($content, $section->refresh()->content);
            $this->assertSame($content, collect($this->getJson($url)->assertOk()->json('data.sections'))->firstWhere('id', $section->id)['content']);
            $exportedContent = $content;
            $exportedContent['semantic'] = (object) [];
            $exports[] = ['name' => $case['name'], 'sectionId' => $section->id, 'content' => $exportedContent, 'first' => json_decode($firstResponse->getContent())->data, 'second' => json_decode($secondResponse->getContent())->data];
        }
        // Optional integration-test handoff to the real Web hydration function.
        $directory = getenv('DIVIDER_ROUNDTRIP_OUTPUT');
        if ($directory !== false && is_dir($directory)) {
            file_put_contents($directory.'/depth-'.$depth.'.json', json_encode($exports, JSON_THROW_ON_ERROR));
        }
    }

    #[DataProvider('depths')]
    public function test_canonical_divider_round_trips_and_invalid_writes_preserve_saved_state(int $depth): void
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => 'Divider contract']);
        $website = $this->initializeWebsite($event);
        $section = $this->blankSection($website);
        $fixtures = json_decode(file_get_contents(__DIR__.'/../Fixtures/divider-contract.json'), true, flags: JSON_THROW_ON_ERROR);
        $divider = collect($fixtures['schemaCases'])->firstWhere('name', 'complete')['element'];
        $contentFor = function (array $element) use ($depth): array {
            for ($index = 0; $index < $depth; $index++) {
                $element = ['id' => 'group-'.$index, 'type' => 'compositionGroup', 'editorName' => 'Group '.($index + 1), 'children' => [$element]];
            }

            return $this->content(['elements' => [$element], 'order' => [['kind' => 'element', 'id' => $element['id']]]]);
        };
        $content = $contentFor($divider);
        $url = "/api/events/{$event->id}/websites/{$website->id}";
        $this->actingAs($owner)->putJson($url.'/sections/'.$section->id, ['content' => $content])->assertOk();
        $this->assertSame($content, $section->refresh()->content);
        $savedSection = collect($this->getJson($url)->assertOk()->json('data.sections'))->firstWhere('id', $section->id);
        $this->assertSame($content, $savedSection['content']);

        foreach ($fixtures['schemaCases'] as $case) {
            if ($case['valid']) {
                continue;
            }
            $this->putJson($url.'/sections/'.$section->id, ['content' => $contentFor($case['element'])])->assertUnprocessable();
            $this->assertSame($content, $section->refresh()->content);
        }
        foreach (['botanical-vine', 'unknown-divider'] as $assetId) {
            $invalid = $divider;
            $invalid['appearance']['assetId'] = $assetId;
            $this->putJson($url.'/sections/'.$section->id, ['content' => $contentFor($invalid)])->assertUnprocessable();
            $this->assertSame($content, $section->refresh()->content);
        }
        foreach ($fixtures['jsonShapeCases'] as $case) {
            $element = $case['element'];
            if ($case['valid']) {
                $element['appearance'] = new \stdClass;
            }
            $response = $this->putJson($url.'/sections/'.$section->id, ['content' => $contentFor($element)]);
            if (! $case['valid']) {
                $response->assertUnprocessable();

                continue;
            }
            $response->assertOk();
            $draft = json_decode($this->getJson($url)->assertOk()->getContent());
            $returned = collect($draft->data->sections)->firstWhere('id', $section->id)->content->compositions->shared->childFlow->elements[0];
            $stored = json_decode($section->refresh()->getRawOriginal('content'))->compositions->shared->childFlow->elements[0];
            for ($index = 0; $index < $depth; $index++) {
                $returned = $returned->children[0];
                $stored = $stored->children[0];
            }
            $this->assertInstanceOf(\stdClass::class, $returned->appearance);
            $this->assertInstanceOf(\stdClass::class, $stored->appearance);
        }
    }

    public function test_modern_rejects_explicit_divider_assets(): void
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => 'Modern Divider contract']);
        $website = $this->initializeWebsite($event, WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $section = $this->blankSection($website);
        $before = $section->content;
        $content = $this->content(['elements' => [['id' => 'divider-1', 'type' => 'divider', 'editorName' => 'Divider 1', 'appearance' => ['assetId' => 'classic-divider-botanical-vine']]], 'order' => [['kind' => 'element', 'id' => 'divider-1']]]);
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/websites/{$website->id}/sections/{$section->id}", ['content' => $content])->assertUnprocessable();
        $this->assertSame($before, $section->refresh()->content);
    }

    private function content(array $childFlow): array
    {
        return ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => $childFlow]]];
    }
}
