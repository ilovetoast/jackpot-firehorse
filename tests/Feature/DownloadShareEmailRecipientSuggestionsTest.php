<?php

namespace Tests\Feature;

use App\Models\DownloadShareEmailRecipientHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

class DownloadShareEmailRecipientSuggestionsTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    public function test_suggestions_requires_authentication(): void
    {
        $this->getJson('/app/downloads/share-email/recipient-suggestions')
            ->assertUnauthorized();
    }

    public function test_contributor_gets_history_only_not_directory(): void
    {
        [$tenant, $brand, $actor] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Co',
            'slug' => 'co-suggest-c',
        ]);
        $actor->tenants()->updateExistingPivot($tenant->id, ['role' => 'member']);
        $actor->brands()->updateExistingPivot($brand->id, ['role' => 'contributor']);

        $colleague = User::create([
            'email' => 'colleague-suggest@example.test',
            'password' => bcrypt('password'),
            'first_name' => 'Zoe',
            'last_name' => 'Team',
            'email_verified_at' => now(),
        ]);
        $colleague->tenants()->attach($tenant->id, ['role' => 'member']);
        $colleague->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        DownloadShareEmailRecipientHistory::recordSend($actor, (int) $tenant->id, 'past-recipient@example.test');

        $res = $this->actingAsTenantBrand($actor, $tenant, $brand)
            ->getJson('/app/downloads/share-email/recipient-suggestions');

        $res->assertOk();
        $res->assertJsonPath('directory_available', false);
        $res->assertJsonCount(1, 'history');
        $res->assertJsonPath('history.0.email', 'past-recipient@example.test');
        $res->assertJsonCount(0, 'directory');
    }

    public function test_brand_admin_gets_directory_when_query_length_at_least_two(): void
    {
        [$tenant, $brand, $actor] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Co',
            'slug' => 'co-suggest-a',
        ]);

        $colleague = User::create([
            'email' => 'zoe-directory@example.test',
            'password' => bcrypt('password'),
            'first_name' => 'Zoe',
            'last_name' => 'Dir',
            'email_verified_at' => now(),
        ]);
        $colleague->tenants()->attach($tenant->id, ['role' => 'member']);
        $colleague->brands()->attach($brand->id, ['role' => 'contributor', 'removed_at' => null]);

        $res = $this->actingAsTenantBrand($actor, $tenant, $brand)
            ->getJson('/app/downloads/share-email/recipient-suggestions?q=Zo');

        $res->assertOk();
        $res->assertJsonPath('directory_available', true);
        $res->assertJsonFragment(['email' => 'zoe-directory@example.test']);
    }

    public function test_directory_not_returned_when_query_shorter_than_two_characters(): void
    {
        [$tenant, $brand, $actor] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Co',
            'slug' => 'co-suggest-b',
        ]);

        $colleague = User::create([
            'email' => 'short-q@example.test',
            'password' => bcrypt('password'),
            'first_name' => 'Amy',
            'last_name' => 'X',
            'email_verified_at' => now(),
        ]);
        $colleague->tenants()->attach($tenant->id, ['role' => 'member']);
        $colleague->brands()->attach($brand->id, ['role' => 'contributor', 'removed_at' => null]);

        $res = $this->actingAsTenantBrand($actor, $tenant, $brand)
            ->getJson('/app/downloads/share-email/recipient-suggestions?q=A');

        $res->assertOk();
        $res->assertJsonPath('directory_available', true);
        $res->assertJsonCount(0, 'directory');
    }
}
