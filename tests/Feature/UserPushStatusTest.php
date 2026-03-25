<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPushStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_post_push_status(): void
    {
        $this->postJson('/app/api/user/push-status', ['enabled' => true])->assertStatus(401);
    }

    public function test_enable_sets_push_enabled_and_prompted_at(): void
    {
        $user = User::factory()->create([
            'push_enabled' => false,
            'push_prompted_at' => null,
        ]);

        $response = $this->actingAs($user)->postJson('/app/api/user/push-status', ['enabled' => true]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('push_enabled', true);

        $user->refresh();
        $this->assertTrue($user->push_enabled);
        $this->assertNotNull($user->push_prompted_at);
    }

    public function test_disable_sets_push_enabled_false_and_may_set_prompted_at(): void
    {
        $user = User::factory()->create([
            'push_enabled' => true,
            'push_prompted_at' => null,
        ]);

        $response = $this->actingAs($user)->postJson('/app/api/user/push-status', ['enabled' => false]);

        $response->assertOk();
        $response->assertJsonPath('push_enabled', false);

        $user->refresh();
        $this->assertFalse($user->push_enabled);
        $this->assertNotNull($user->push_prompted_at);
    }
}
