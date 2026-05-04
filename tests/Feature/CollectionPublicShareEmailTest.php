<?php

namespace Tests\Feature;

use App\Mail\ShareCollectionPublicLinkInstructions;
use App\Models\Collection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CollectionPublicShareEmailTest extends TestCase
{
    use RefreshDatabase;

    private function setupAdminWithShareCollection(): array
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $tenant->update(['manual_plan_override' => 'enterprise']);
        $brand = $tenant->brands()->where('is_default', true)->firstOrFail();
        $brand->update(['name' => 'B', 'slug' => 'b']);
        $user = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'dmin',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->tenants()->attach($tenant->id, ['role' => 'member']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $collection = Collection::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Shared',
            'slug' => 'shared',
            'visibility' => 'brand',
            'is_public' => true,
            'public_share_token' => 'tokemailsharetest0001',
            'public_password_hash' => Hash::make('secret-share-pw'),
            'public_password_set_at' => now(),
        ]);

        return [$tenant, $brand, $user, $collection];
    }

    public function test_admin_can_send_public_share_email_without_password_in_body(): void
    {
        Mail::fake();
        [$tenant, $brand, $user, $collection] = $this->setupAdminWithShareCollection();

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson("/app/collections/{$collection->id}/public-share-email", [
                'email' => 'client@example.com',
                'include_password' => false,
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertOk()
            ->assertJson(['sent' => true]);

        Mail::assertSent(ShareCollectionPublicLinkInstructions::class, function (ShareCollectionPublicLinkInstructions $mail) {
            return $mail->verifiedPasswordPlain === null
                && str_contains($mail->shareUrl, 'tokemailsharetest0001');
        });
    }

    public function test_admin_can_include_password_after_verification(): void
    {
        Mail::fake();
        [$tenant, $brand, $user, $collection] = $this->setupAdminWithShareCollection();

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson("/app/collections/{$collection->id}/public-share-email", [
                'email' => 'client@example.com',
                'include_password' => true,
                'share_password' => 'secret-share-pw',
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertOk();

        Mail::assertSent(ShareCollectionPublicLinkInstructions::class, function (ShareCollectionPublicLinkInstructions $mail) {
            return $mail->verifiedPasswordPlain === 'secret-share-pw';
        });
    }

    public function test_wrong_password_rejected_when_including_in_email(): void
    {
        Mail::fake();
        [$tenant, $brand, $user, $collection] = $this->setupAdminWithShareCollection();

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson("/app/collections/{$collection->id}/public-share-email", [
                'email' => 'client@example.com',
                'include_password' => true,
                'share_password' => 'wrong',
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_viewer_cannot_send_share_email(): void
    {
        Mail::fake();
        [$tenant, $brand, , $collection] = $this->setupAdminWithShareCollection();

        $viewer = User::create([
            'email' => 'viewer@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'V',
            'last_name' => 'iewer',
        ]);
        $viewer->forceFill(['email_verified_at' => now()])->save();
        $viewer->tenants()->attach($tenant->id, ['role' => 'member']);
        $viewer->brands()->attach($brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $this->actingAs($viewer)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson("/app/collections/{$collection->id}/public-share-email", [
                'email' => 'client@example.com',
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertStatus(403);

        Mail::assertNothingSent();
    }
}
