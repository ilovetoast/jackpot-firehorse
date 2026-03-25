<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPushPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_update_push_preference(): void
    {
        $response = $this->postJson('/app/api/user/push-preference', [
            'prompted' => true,
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_mark_prompted(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/app/api/user/push-preference', [
            'prompted' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('push_enabled', false);

        $user->refresh();
        $this->assertNotNull($user->push_prompted_at);
        $this->assertFalse($user->push_enabled);
    }

    public function test_authenticated_user_can_enable_push(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/app/api/user/push-preference', [
            'enabled' => true,
            'prompted' => true,
        ]);

        $response->assertOk();

        $user->refresh();
        $this->assertTrue($user->push_enabled);
        $this->assertNotNull($user->push_prompted_at);
    }
}
