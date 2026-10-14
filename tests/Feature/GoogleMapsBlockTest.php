<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Invitations\CreateInvitation;
use App\Actions\Websites\PublishWebsite;
use App\Models\User;
use App\Website\Publishing\PublishedWebsiteSnapshotBuilder;
use App\Website\Publishing\PublishedWebsiteSnapshotReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleMapsBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_round_trip_snapshot_isolation_and_both_guest_audiences(): void
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => 'Map website']);
        $website = $this->initializeWebsite($event);
        $section = $website->sections()->where('type', 'hero')->sole();
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/google-maps-contract.json'), true);
        $map = ['id' => 'map', 'type' => 'map', 'editorName' => 'Google Maps', 'embedUrl' => $fixture['url'], 'appearance' => ['height' => 'medium', 'cornerRadius' => 'large', 'alignment' => 'center', 'outerSpacing' => ['top' => 'm']]];
        $content = ['semantic' => [], 'compositions' => ['shared' => ['childFlow' => ['elements' => [$map], 'order' => [['kind' => 'element', 'id' => 'map']]]]]];
        $path = "/api/events/{$event->id}/websites/{$website->id}/sections/{$section->id}";
        $this->actingAs($owner)->draftPutJson($path, ['content' => $content])->assertOk();
        $this->assertSame($content, $section->refresh()->content);
        app(PublishedWebsiteSnapshotReader::class)->decode(app(PublishedWebsiteSnapshotBuilder::class)->build($website)->toJson());
        app(PublishWebsite::class)->handle($event, $website);
        $invitation = app(CreateInvitation::class)->handle($event, [['first_name' => 'Guest']]);
        $token = $invitation->currentPrivateLink->encrypted_token;
        $public = $this->getJson('/api/public/events/'.$event->slug.'/site')->assertOk()->json('data.website');
        $private = $this->draftPostJson('/api/private-invitations/site', ['token' => $token])->assertOk()->json('data.website');
        $find = fn ($site) => collect($site['sections'])->firstWhere('id', $section->id)['content']['compositions']['shared']['childFlow']['elements'][0];
        $this->assertSame($map, $find($public));
        $this->assertSame($map, $find($private));
        foreach ([$fixture['invalid'][0], $fixture['iframe']] as $invalid) {
            $bad = $content;
            $bad['compositions']['shared']['childFlow']['elements'][0]['embedUrl'] = $invalid;
            $this->draftPutJson($path, ['content' => $bad])->assertUnprocessable();
            $this->assertSame($content, $section->refresh()->content);
        }
        $bad = $content;
        $bad['compositions']['shared']['childFlow']['elements'][0]['appearance'] = [];
        $this->draftPutJson($path, ['content' => $bad])->assertUnprocessable();
        $content['compositions']['shared']['childFlow']['elements'][0]['appearance']['height'] = 'small';
        $this->draftPutJson($path, ['content' => $content])->assertOk();
        $this->assertSame($map, $find($this->getJson('/api/public/events/'.$event->slug.'/site')->assertOk()->json('data.website')));
        $this->assertSame($map, $find($this->draftPostJson('/api/private-invitations/site', ['token' => $token])->assertOk()->json('data.website')));
        foreach (['wide', 320, null] as $width) {
            $bad = $content;
            $bad['compositions']['shared']['childFlow']['elements'][0]['appearance']['width'] = $width;
            $this->draftPutJson($path, ['content' => $bad])->assertUnprocessable();
            $this->assertSame($content, $section->refresh()->content);
        }
        foreach (['subtle', '', null, 1] as $shadow) {
            $bad = $content;
            $bad['compositions']['shared']['childFlow']['elements'][0]['appearance']['shadow'] = $shadow;
            $this->draftPutJson($path, ['content' => $bad])->assertUnprocessable();
            $this->assertSame($content, $section->refresh()->content);
        }
        foreach (['small', 'medium', 'large', 'full'] as $index => $width) {
            $content['compositions']['shared']['childFlow']['elements'][0]['appearance']['shadow'] = ['none', 'soft', 'medium', 'strong'][$index];
            $content['compositions']['shared']['childFlow']['elements'][0]['appearance']['width'] = $width;
            $this->draftPutJson($path, ['content' => $content])->assertOk();
            $this->assertEquals($content, $section->refresh()->content);
            app(PublishWebsite::class)->handle($event, $website);
            $expected = $section->content['compositions']['shared']['childFlow']['elements'][0];
            $this->assertSame($expected, $find($this->getJson('/api/public/events/'.$event->slug.'/site')->assertOk()->json('data.website')));
            $this->assertSame($expected, $find($this->draftPostJson('/api/private-invitations/site', ['token' => $token])->assertOk()->json('data.website')));
        }

    }
}
