<?php

namespace App\Examples;

/**
 * Activity Logging Examples
 * 
 * This file demonstrates various usage patterns for the activity logging system.
 * These are examples only - not meant to be executed directly.
 */

use App\Enums\EventType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ActivityRecorder;

class ActivityLoggingExamples
{
    /**
     * Example 1: Asset Upload (Explicit Logging)
     * 
     * When an asset is uploaded, explicitly log it with metadata.
     */
    public function exampleAssetUpload(Tenant $tenant, Brand $brand, User $user, $file)
    {
        // Create the asset
        $asset = $brand->assets()->create([
            'name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);

        // Explicitly log the upload
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::ASSET_UPLOADED,
            subject: $asset,
            actor: $user,
            brand: $brand,
            metadata: [
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'original_name' => $file->getClientOriginalName(),
            ]
        );

        return $asset;
    }

    /**
     * Example 2: Asset Metadata Update (Automatic via Trait)
     * 
     * If Asset model uses RecordsActivity trait, updates are logged automatically.
     * Only changed attributes are logged (diff).
     */
    public function exampleAssetUpdate(Asset $asset)
    {
        // The trait automatically logs this update
        $asset->update([
            'name' => 'Updated Name',
            'description' => 'Updated description',
        ]);

        // Automatically creates activity event:
        // - event_type: 'asset.updated' (or custom name)
        // - metadata: {
        //     'changed': {'name' => 'Updated Name', 'description' => 'Updated description'},
        //     'original': {'name' => 'Old Name', 'description' => 'Old description'}
        //   }
    }

    /**
     * Example 3: Asset Download Created (Explicit - Required)
     * 
     * Downloads must be logged explicitly, not via trait.
     */
    public function exampleAssetDownloadCreated(Asset $asset, User $user)
    {
        // Create download record (if you have asset_downloads table)
        // $download = $asset->downloads()->create([
        //     'status' => 'pending',
        //     'user_id' => $user->id,
        // ]);

        // Log download creation
        ActivityRecorder::record(
            tenant: $asset->brand->tenant,
            eventType: EventType::ASSET_DOWNLOAD_CREATED,
            subject: $asset,
            actor: $user,
            brand: $asset->brand,
            metadata: [
                // 'download_id' => $download->id,
                'format' => 'original', // or 'thumbnail', 'web', etc.
            ]
        );
    }

    /**
     * Example 4: Asset Download Completed (Explicit)
     * 
     * Log when download processing completes.
     */
    public function exampleAssetDownloadCompleted(Asset $asset, User $user, $downloadId, $fileSize, $duration)
    {
        // Update download record
        // $download->update(['status' => 'completed', 'completed_at' => now()]);

        // Log download completion
        ActivityRecorder::record(
            tenant: $asset->brand->tenant,
            eventType: EventType::ASSET_DOWNLOAD_COMPLETED,
            subject: $asset,
            actor: $user,
            brand: $asset->brand,
            metadata: [
                'download_id' => $downloadId,
                'file_size' => $fileSize,
                'duration_ms' => $duration,
            ]
        );
    }

    /**
     * Example 5: Asset Preview (Explicit)
     * 
     * Log when an asset is previewed.
     */
    public function exampleAssetPreview(Asset $asset, User $user)
    {
        ActivityRecorder::record(
            tenant: $asset->brand->tenant,
            eventType: EventType::ASSET_PREVIEWED,
            subject: $asset,
            actor: $user,
            brand: $asset->brand,
            metadata: [
                'preview_type' => 'thumbnail', // or 'full', 'lightbox', etc.
            ]
        );
    }

    /**
     * Example 6: Asset Share Link Created
     */
    public function exampleAssetShareLinkCreated(Asset $asset, User $user)
    {
        // Create share link
        // $shareLink = $asset->shareLinks()->create([
        //     'token' => Str::random(32),
        //     'expires_at' => now()->addDays(7),
        // ]);

        ActivityRecorder::record(
            tenant: $asset->brand->tenant,
            eventType: EventType::ASSET_SHARED_LINK_CREATED,
            subject: $asset,
            actor: $user,
            brand: $asset->brand,
            metadata: [
                // 'share_link_id' => $shareLink->id,
                'expires_at' => now()->addDays(7)->toIso8601String(),
            ]
        );
    }

    /**
     * Example 7: Asset Share Link Accessed (Guest)
     * 
     * When a guest accesses a shared link.
     */
    public function exampleAssetShareLinkAccessed(Asset $asset)
    {
        ActivityRecorder::guest(
            tenant: $asset->brand->tenant,
            eventType: EventType::ASSET_SHARED_LINK_ACCESSED,
            subject: $asset,
            metadata: [
                // 'share_link_id' => $shareLink->id,
            ]
        );
    }

    /**
     * Example 8: System Event (No User Actor)
     * 
     * For system-generated events like errors, background jobs, etc.
     */
    public function exampleSystemEvent(Tenant $tenant)
    {
        ActivityRecorder::system(
            tenant: $tenant,
            eventType: EventType::SYSTEM_ERROR,
            subject: null,
            metadata: [
                'error' => 'Something went wrong',
                'context' => 'background_job',
            ]
        );
    }

    /**
     * Example 9: From Queued Job
     * 
     * Safe to use from queued jobs - automatically handles missing request context.
     */
    public function exampleFromQueuedJob(Asset $asset)
    {
        // Process asset in background...
        
        ActivityRecorder::system(
            tenant: $asset->brand->tenant,
            eventType: EventType::ASSET_VERSION_ADDED,
            subject: $asset,
            metadata: [
                'version' => 'v2',
                'processing_time_ms' => 1500,
            ]
        );
    }

    /**
     * Example 10: User Invited
     */
    public function exampleUserInvited(Tenant $tenant, User $inviter, User $invitee)
    {
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_INVITED,
            subject: $invitee,
            actor: $inviter,
            metadata: [
                'email' => $invitee->email,
                'role' => 'member',
            ]
        );
    }

    /**
     * Example 11: Querying Activity Events
     */
    public function exampleQueryingEvents(Tenant $tenant, Brand $brand, Asset $asset)
    {
        // Recent events for tenant
        $events = \App\Models\ActivityEvent::forTenant($tenant->id)
            ->recent(50)
            ->get();

        // Events for a specific brand
        $events = \App\Models\ActivityEvent::forTenant($tenant->id)
            ->forBrand($brand->id)
            ->recent(50)
            ->get();

        // Events for a specific asset
        $events = \App\Models\ActivityEvent::forTenant($tenant->id)
            ->forSubject(Asset::class, $asset->id)
            ->recent(50)
            ->get();

        // Events of a specific type
        $events = \App\Models\ActivityEvent::forTenant($tenant->id)
            ->ofType(EventType::ASSET_DOWNLOAD_CREATED)
            ->recent(50)
            ->get();

        // With relationships
        $events = \App\Models\ActivityEvent::forTenant($tenant->id)
            ->with(['actor', 'subject', 'brand'])
            ->recent(50)
            ->get();

        foreach ($events as $event) {
            // Access event properties
            $event->event_type; // 'asset.uploaded'
            $event->metadata['file_size']; // Custom metadata
            $event->actor->name; // User name (if actor is User)
            $event->subject->name; // Asset name (if subject is Asset)
            $event->ip_address; // IP from request context
            $event->user_agent; // User agent from request context
        }
    }
}
