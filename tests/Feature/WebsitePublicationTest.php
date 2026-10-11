<?php

namespace Tests\Feature;

use App\Actions\Websites\CreateWebsiteProject;
use App\Actions\Websites\PublishWebsite;
use App\Enums\EventMembershipRole;
use App\Enums\PlatformRole;
use App\Models\Event;
use App\Models\User;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WebsitePublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_supports_multiple_projects_and_nullable_single_publication_pointer(): void
    {
        [$event, $owner] = $this->event(EventMembershipRole::Owner);
        $first = $this->project($event, 'First');
        $second = $this->project($event, 'Second');

        $this->assertNull($event->publishedWebsite);
        $this->assertCount(2, $event->websiteProjects);

        $this->actingAs($owner)->postJson("/api/events/{$event->id}/websites/{$first->id}/publish")
            ->assertOk()->assertJsonPath('data.isPublished', true);
        $this->assertTrue($event->refresh()->publishedWebsite->is($first));

        $firstUpdatedAt = $first->updated_at;
        $this->actingAs($owner)->postJson("/api/events/{$event->id}/websites/{$first->id}/publish")->assertOk();
        $this->assertTrue($first->refresh()->updated_at->equalTo($firstUpdatedAt));

        $this->actingAs($owner)->postJson("/api/events/{$event->id}/websites/{$second->id}/publish")->assertOk();
        $this->assertTrue($event->refresh()->publishedWebsite->is($second));
        $this->assertDatabaseHas('websites', ['id' => $first->id, 'name' => 'First']);

        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/published-website")
            ->assertOk()->assertJsonPath('data.publishedWebsiteId', null);
        $this->assertNull($event->refresh()->published_website_id);
    }

    public function test_publication_is_event_scoped_authorized_and_supports_current_policy_roles(): void
    {
        [$event, $owner] = $this->event(EventMembershipRole::Owner);
        [, $admin] = $this->event(EventMembershipRole::Admin, $event);
        $project = $this->project($event, 'Main');
        $url = "/api/events/{$event->id}/websites/{$project->id}/publish";

        $this->postJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url)->assertForbidden();
        $this->actingAs($admin)->postJson($url)->assertOk();
        $this->actingAs($owner)->postJson($url)->assertOk();
        $this->actingAs(User::factory()->create(['platform_role' => PlatformRole::SuperAdmin]))->postJson($url)->assertOk();

        [$foreign] = $this->event(EventMembershipRole::Owner);
        $foreignProject = $this->project($foreign, 'Foreign');
        $this->actingAs($owner)->postJson("/api/events/{$event->id}/websites/{$foreignProject->id}/publish")->assertNotFound();

        $this->expectException(ValidationException::class);
        app(PublishWebsite::class)->handle($event, $foreignProject);
    }

    public function test_deleting_published_website_unpublishes_and_event_deletion_remains_valid(): void
    {
        [$event] = $this->event(EventMembershipRole::Owner);
        $project = $this->project($event, 'Main');
        app(PublishWebsite::class)->handle($event, $project);

        $project->delete();
        $this->assertNull($event->refresh()->published_website_id);

        $replacement = $this->project($event, 'Replacement');
        app(PublishWebsite::class)->handle($event, $replacement);
        $event->delete();
        $this->assertDatabaseMissing('websites', ['id' => $replacement->id]);
    }

    /** @return array{Event, User} */
    private function event(EventMembershipRole $role, ?Event $event = null): array
    {
        $user = User::factory()->create();
        $event ??= Event::factory()->create();
        $event->memberships()->create(['user_id' => $user->id, 'role' => $role]);

        return [$event, $user];
    }

    private function project(Event $event, string $name)
    {
        return app(CreateWebsiteProject::class)->handle($event, $name, WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
    }
}
