<?php

namespace Tests\Unit\Listeners;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Events\AssetPendingApproval;
use App\Listeners\SendAssetPendingApprovalNotification;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Send Asset Pending Approval Notification Test
 *
 * Phase L.6.3 — Approval Notifications
 *
 * Tests that:
 * - Correct recipients are selected (users with asset.publish permission)
 * - Tenant and brand scoping is enforced
 * - Uploader is excluded if they are not an approver
 * - Email is sent to all approvers
 * - Failures are logged but non-blocking
 * - Listener does not fire for non-approval uploads
 */
class SendAssetPendingApprovalNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $uploader;
    protected User $approver;
    protected User $nonApprover;
    protected Asset $asset;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        Permission::create(['name' => 'asset.publish', 'guard_name' => 'web']);

        // Create tenant and brand
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        // Create uploader (no publish permission)
        $this->uploader = User::create([
            'email' => 'uploader@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Uploader',
            'last_name' => 'User',
        ]);
        $this->uploader->tenants()->attach($this->tenant->id, ['role' => 'contributor']);
        $this->uploader->brands()->attach($this->brand->id);

        // Create approver (has publish permission)
        $this->approver = User::create([
            'email' => 'approver@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Approver',
            'last_name' => 'User',
        ]);
        $this->approver->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        $this->approver->brands()->attach($this->brand->id);

        // Create role with asset.publish permission
        $approverRole = Role::create(['name' => 'manager', 'guard_name' => 'web']);
        $approverRole->givePermissionTo('asset.publish');
        $this->approver->assignRole($approverRole);

        // Create non-approver (different tenant)
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
        ]);
        $this->nonApprover = User::create([
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Other',
            'last_name' => 'User',
        ]);
        $this->nonApprover->tenants()->attach($otherTenant->id, ['role' => 'manager']);
        $otherRole = Role::create(['name' => 'manager', 'guard_name' => 'web']);
        $otherRole->givePermissionTo('asset.publish');
        $this->nonApprover->assignRole($otherRole);

        // Create category with requires_approval
        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Test Category',
            'slug' => 'test-category',
            'requires_approval' => true,
        ]);

        // Create storage bucket
        $storageBucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        // Create upload session
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->uploader->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
        ]);

        // Create asset (pending approval)
        $this->asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'upload_session_id' => $uploadSession->id,
            'type' => AssetType::ASSET,
            'status' => AssetStatus::HIDDEN,
            'original_filename' => 'test.jpg',
            'storage_root_path' => 'assets/test/test.jpg',
            'size_bytes' => 1024,
            'mime_type' => 'image/jpeg',
            'metadata' => [
                'category_id' => $this->category->id,
            ],
        ]);

        Mail::fake();
    }

    public function test_notification_sent_to_approvers(): void
    {
        $event = new AssetPendingApproval($this->asset, $this->uploader, $this->category->name);
        $listener = new SendAssetPendingApprovalNotification();
        
        $listener->handle($event);

        // Assert email was sent to approver
        Mail::assertSent(\App\Mail\AssetPendingApprovalNotification::class, function ($mail) {
            return $mail->hasTo($this->approver->email);
        });

        // Assert email was NOT sent to uploader (they don't have permission)
        Mail::assertNotSent(\App\Mail\AssetPendingApprovalNotification::class, function ($mail) {
            return $mail->hasTo($this->uploader->email);
        });

        // Assert email was NOT sent to non-approver (different tenant)
        Mail::assertNotSent(\App\Mail\AssetPendingApprovalNotification::class, function ($mail) {
            return $mail->hasTo($this->nonApprover->email);
        });
    }

    public function test_notification_respects_tenant_scoping(): void
    {
        // Create another tenant with an approver
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant 2',
            'slug' => 'other-tenant-2',
        ]);

        $otherApprover = User::create([
            'email' => 'other-approver@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Other',
            'last_name' => 'Approver',
        ]);
        $otherApprover->tenants()->attach($otherTenant->id, ['role' => 'manager']);
        $otherRole = Role::create(['name' => 'manager', 'guard_name' => 'web']);
        $otherRole->givePermissionTo('asset.publish');
        $otherApprover->assignRole($otherRole);

        $event = new AssetPendingApproval($this->asset, $this->uploader, $this->category->name);
        $listener = new SendAssetPendingApprovalNotification();
        
        $listener->handle($event);

        // Assert email was NOT sent to approver from other tenant
        Mail::assertNotSent(\App\Mail\AssetPendingApprovalNotification::class, function ($mail) use ($otherApprover) {
            return $mail->hasTo($otherApprover->email);
        });
    }

    public function test_notification_respects_brand_scoping(): void
    {
        // Create another brand in same tenant
        $otherBrand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Brand',
            'slug' => 'other-brand',
        ]);

        // Create approver assigned to other brand only
        $otherBrandApprover = User::create([
            'email' => 'other-brand-approver@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Other',
            'last_name' => 'Brand Approver',
        ]);
        $otherBrandApprover->tenants()->attach($this->tenant->id, ['role' => 'contributor']);
        $otherBrandApprover->brands()->attach($otherBrand->id);
        $otherBrandRole = Role::create(['name' => 'contributor', 'guard_name' => 'web']);
        $otherBrandRole->givePermissionTo('asset.publish');
        $otherBrandApprover->assignRole($otherBrandRole);

        $event = new AssetPendingApproval($this->asset, $this->uploader, $this->category->name);
        $listener = new SendAssetPendingApprovalNotification();
        
        $listener->handle($event);

        // Assert email was NOT sent to approver from other brand
        Mail::assertNotSent(\App\Mail\AssetPendingApprovalNotification::class, function ($mail) use ($otherBrandApprover) {
            return $mail->hasTo($otherBrandApprover->email);
        });
    }

    public function test_notification_excludes_uploader_if_not_approver(): void
    {
        $event = new AssetPendingApproval($this->asset, $this->uploader, $this->category->name);
        $listener = new SendAssetPendingApprovalNotification();
        
        $listener->handle($event);

        // Uploader should not receive email (they don't have asset.publish)
        Mail::assertNotSent(\App\Mail\AssetPendingApprovalNotification::class, function ($mail) {
            return $mail->hasTo($this->uploader->email);
        });
    }

    public function test_notification_excludes_uploader_even_if_they_are_approver(): void
    {
        // Give uploader publish permission
        $this->uploader->setRoleForTenant($this->tenant, 'manager');
        $managerRole = Role::where('name', 'manager')->first();
        $this->uploader->assignRole($managerRole);

        $event = new AssetPendingApproval($this->asset, $this->uploader, $this->category->name);
        $listener = new SendAssetPendingApprovalNotification();
        
        $listener->handle($event);

        // Uploader should NOT receive email even if they have approval permission
        // (they already know they uploaded the asset)
        Mail::assertNotSent(\App\Mail\AssetPendingApprovalNotification::class, function ($mail) {
            return $mail->hasTo($this->uploader->email);
        });
    }

    public function test_listener_does_not_throw_on_failure(): void
    {
        // Create event with invalid asset (missing tenant)
        $invalidAsset = new Asset();
        $invalidAsset->id = 99999;
        $invalidAsset->tenant_id = null;
        
        $event = new AssetPendingApproval($invalidAsset, $this->uploader, 'Test Category');
        $listener = new SendAssetPendingApprovalNotification();
        
        // Should not throw
        $listener->handle($event);

        // No emails should be sent
        Mail::assertNothingSent();
    }

    public function test_email_contains_correct_content(): void
    {
        $event = new AssetPendingApproval($this->asset, $this->uploader, $this->category->name);
        $listener = new SendAssetPendingApprovalNotification();
        
        $listener->handle($event);

        Mail::assertSent(\App\Mail\AssetPendingApprovalNotification::class, function ($mail) {
            return $mail->hasTo($this->approver->email)
                && $mail->asset->id === $this->asset->id
                && $mail->uploader->id === $this->uploader->id
                && $mail->categoryName === $this->category->name;
        });
    }
}
