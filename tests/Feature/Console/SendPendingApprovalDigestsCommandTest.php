<?php

namespace Tests\Feature\Console;

use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Mail\PendingApprovalsDigestMail;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SendPendingApprovalDigestsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_digest_to_approver_when_plan_allows_notifications(): void
    {
        config(['mail.automations_enabled' => true]);

        Permission::create(['name' => 'asset.publish', 'guard_name' => 'web']);
        $managerRole = Role::create(['name' => 'manager', 'guard_name' => 'web']);
        $managerRole->givePermissionTo('asset.publish');

        $tenant = Tenant::create([
            'name' => 'Digest Tenant',
            'slug' => 'digest-tenant',
            'manual_plan_override' => 'pro',
        ]);

        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Digest Brand',
            'slug' => 'digest-brand',
        ]);

        $approver = User::create([
            'email' => 'digest-approver@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Ap',
            'last_name' => 'Prover',
        ]);
        $approver->tenants()->attach($tenant->id, ['role' => 'manager']);
        $approver->brands()->attach($brand->id);
        $approver->assignRole($managerRole);

        $uploader = User::create([
            'email' => 'digest-up@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Up',
            'last_name' => 'Loader',
        ]);
        $uploader->tenants()->attach($tenant->id, ['role' => 'contributor']);
        $uploader->brands()->attach($brand->id);

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Cat',
            'slug' => 'cat',
            'requires_approval' => true,
        ]);

        $storageBucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'b',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $uploadSession = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $uploader->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
        ]);

        Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $uploadSession->id,
            'type' => AssetType::ASSET,
            'status' => AssetStatus::HIDDEN,
            'original_filename' => 'a.jpg',
            'storage_root_path' => 'assets/test/a.jpg',
            'size_bytes' => 1024,
            'mime_type' => 'image/jpeg',
            'approval_status' => ApprovalStatus::PENDING,
            'submitted_by_prostaff' => false,
            'metadata' => ['category_id' => $category->id],
        ]);

        Mail::fake();

        Artisan::call('approvals:send-pending-digests');

        Mail::assertSent(PendingApprovalsDigestMail::class, function (PendingApprovalsDigestMail $mail) use ($approver, $brand) {
            return $mail->hasTo($approver->email)
                && $mail->brand->is($brand)
                && ($mail->teamStats['count'] ?? 0) >= 1;
        });
    }
}
