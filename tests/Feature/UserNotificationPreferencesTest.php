<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserNotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_notification_preferences(): void
    {
        $this->getJson('/app/api/user/notification-preferences')->assertStatus(401);
    }

    public function test_authenticated_user_gets_defaults(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/app/api/user/notification-preferences');

        $response->assertOk();
        $response->assertJsonPath('preferences.activity.push', true);
        $response->assertJsonPath('preferences.account.push', true);
        $response->assertJsonPath('preferences.system.push', false);
    }

    public function test_update_merges_preferences_without_changing_device_push_enabled(): void
    {
        $user = User::factory()->create([
            'push_enabled' => true,
            'push_prompted_at' => now(),
            'notification_preferences' => null,
        ]);

        $response = $this->actingAs($user)->postJson('/app/api/user/notification-preferences', [
            'preferences' => [
                'activity' => ['push' => false],
                'account' => ['push' => false],
                'system' => ['push' => false],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('push_enabled', true);

        $user->refresh();
        $this->assertTrue($user->push_enabled);
        $this->assertFalse($user->getNotificationPreferences()['activity']['push']);
    }
}
