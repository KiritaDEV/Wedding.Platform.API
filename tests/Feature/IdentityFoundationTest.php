<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdentityFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_ids_are_generated_as_ulids_and_can_be_used_to_retrieve_users(): void
    {
        $user = User::factory()->create();

        $this->assertIsString($user->id);
        $this->assertTrue(Str::isUlid($user->id));
        $this->assertTrue($user->is(User::findOrFail($user->id)));
    }

    public function test_users_have_the_normal_platform_role_by_default(): void
    {
        $user = User::query()->create([
            'name' => 'Normal User',
            'email' => 'normal@example.com',
            'password' => 'password',
        ])->refresh();

        $this->assertSame(PlatformRole::User, $user->platform_role);
        $this->assertFalse($user->isSuperAdmin());
    }

    public function test_super_admin_users_can_be_created_with_the_factory_state(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertSame(PlatformRole::SuperAdmin, $superAdmin->platform_role);
        $this->assertTrue($superAdmin->isSuperAdmin());
    }

    public function test_ulid_users_are_compatible_with_sanctum_tokens(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('test-token');

        $this->assertSame($user->id, $token->accessToken->tokenable_id);
        $this->assertTrue($token->accessToken->tokenable->is($user));
    }
}
