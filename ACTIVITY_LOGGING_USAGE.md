# Activity Logging System - Usage Guide

## Overview

The activity logging system provides append-only, audit-grade event tracking for your multi-tenant SaaS application. It's designed for high-volume events and supports tenant-level, brand-level, and asset-level activity feeds.

## Core Components

1. **ActivityEvent Model** - Immutable event records
2. **EventType Enum** - Centralized event type registry
3. **ActivityRecorder Service** - Single entry point for logging
4. **RecordsActivity Trait** - Automatic model event logging

## Basic Usage

### 1. Automatic Model Event Logging (via Trait)

Add the `RecordsActivity` trait to any model to automatically log created, updated, deleted, and restored events:

```php
use App\Traits\RecordsActivity;

class Asset extends Model
{
    use RecordsActivity;
    
    // Optional: Customize event names
    protected static $activityEventNames = [
        'created' => EventType::ASSET_UPLOADED,
        'updated' => EventType::ASSET_METADATA_UPDATED,
    ];
    
    // Optional: Disable automatic logging
    // protected static $recordActivity = false;
}
```

**What gets logged automatically:**
- `created` → `asset.created` (or custom name)
- `updated` → `asset.updated` with diff of changed attributes
- `deleted` → `asset.deleted`
- `restored` → `asset.restored` (if using soft deletes)

**Important:** The trait only logs attribute diffs for updates, not full model payloads.

### 2. Explicit Event Logging (via ActivityRecorder)

For events that shouldn't be auto-logged (like downloads, previews, shares), use `ActivityRecorder` directly:

```php
use App\Services\ActivityRecorder;
use App\Enums\EventType;

// Basic usage
ActivityRecorder::record(
    tenant: $tenant,                    // Tenant ID or Tenant model
    eventType: EventType::ASSET_UPLOADED,
    subject: $asset,                    // The model this event is about
    actor: $user,                       // Optional: User model (auto-detected from Auth if null)
    brand: $brand,                      // Optional: Brand ID or Brand model
    metadata: ['size' => 1024, 'type' => 'image']  // Optional: Additional data
);
```

### 3. Convenience Methods

For system, API, or guest events:

```php
// System event (no user actor)
ActivityRecorder::system(
    tenant: $tenant,
    eventType: EventType::SYSTEM_ERROR,
    subject: null,
    metadata: ['error' => 'Something went wrong']
);

// API event
ActivityRecorder::api(
    tenant: $tenant,
    eventType: EventType::ASSET_UPLOADED,
    subject: $asset
);

// Guest event
ActivityRecorder::guest(
    tenant: $tenant,
    eventType: EventType::ASSET_SHARED_LINK_ACCESSED,
    subject: $asset
);
```

## Example: Asset Upload

```php
use App\Services\ActivityRecorder;
use App\Enums\EventType;

// In AssetController or AssetService
public function upload(Request $request, Brand $brand)
{
    $asset = $brand->assets()->create([
        'name' => $request->name,
        'file_path' => $path,
        // ... other fields
    ]);
    
    // Explicitly log the upload with metadata
    ActivityRecorder::record(
        tenant: $brand->tenant,
        eventType: EventType::ASSET_UPLOADED,
        subject: $asset,
        actor: auth()->user(),
        brand: $brand,
        metadata: [
            'file_size' => $request->file('file')->getSize(),
            'mime_type' => $request->file('file')->getMimeType(),
            'original_name' => $request->file('file')->getClientOriginalName(),
        ]
    );
    
    return $asset;
}
```

## Example: Asset Metadata Update (via Trait)

If your Asset model uses `RecordsActivity` trait:

```php
class Asset extends Model
{
    use RecordsActivity;
    
    protected static $activityEventNames = [
        'updated' => EventType::ASSET_METADATA_UPDATED,
    ];
}

// When you update the asset:
$asset->update(['name' => 'New Name', 'description' => 'New description']);

// Automatically logs:
// - event_type: 'asset.metadata_updated'
// - metadata: {
//     'changed': {'name' => 'New Name', 'description' => 'New description'},
//     'original': {'name' => 'Old Name', 'description' => 'Old description'}
//   }
```

## Example: Asset Download (Explicit - Required)

Downloads must be logged explicitly, not via trait:

```php
use App\Services\ActivityRecorder;
use App\Enums\EventType;

// When download is initiated
public function download(Asset $asset)
{
    // Create download record (if you have asset_downloads table)
    $download = $asset->downloads()->create([
        'status' => 'pending',
        'user_id' => auth()->id(),
    ]);
    
    // Log download creation
    ActivityRecorder::record(
        tenant: $asset->brand->tenant,
        eventType: EventType::ASSET_DOWNLOAD_CREATED,
        subject: $asset,
        actor: auth()->user(),
        brand: $asset->brand,
        metadata: [
            'download_id' => $download->id,
            'format' => $request->input('format', 'original'),
        ]
    );
    
    // Process download...
    
    // When download completes
    $download->update(['status' => 'completed', 'completed_at' => now()]);
    
    // Log download completion
    ActivityRecorder::record(
        tenant: $asset->brand->tenant,
        eventType: EventType::ASSET_DOWNLOAD_COMPLETED,
        subject: $asset,
        actor: auth()->user(),
        brand: $asset->brand,
        metadata: [
            'download_id' => $download->id,
            'file_size' => $fileSize,
            'duration_ms' => $duration,
        ]
    );
}
```

## Example: Asset Preview

```php
use App\Services\ActivityRecorder;
use App\Enums\EventType;

public function preview(Asset $asset)
{
    // Log preview event
    ActivityRecorder::record(
        tenant: $asset->brand->tenant,
        eventType: EventType::ASSET_PREVIEWED,
        subject: $asset,
        actor: auth()->user(),
        brand: $asset->brand,
        metadata: [
            'preview_type' => 'thumbnail', // or 'full', 'lightbox', etc.
        ]
    );
    
    return response()->file($asset->preview_path);
}
```

## Example: Asset Share Link

```php
use App\Services\ActivityRecorder;
use App\Enums\EventType;

public function createShareLink(Asset $asset)
{
    $shareLink = $asset->shareLinks()->create([
        'token' => Str::random(32),
        'expires_at' => now()->addDays(7),
    ]);
    
    // Log share link creation
    ActivityRecorder::record(
        tenant: $asset->brand->tenant,
        eventType: EventType::ASSET_SHARED_LINK_CREATED,
        subject: $asset,
        actor: auth()->user(),
        brand: $asset->brand,
        metadata: [
            'share_link_id' => $shareLink->id,
            'expires_at' => $shareLink->expires_at->toIso8601String(),
        ]
    );
    
    return $shareLink;
}

// When someone accesses the shared link (guest access)
public function accessSharedLink(string $token)
{
    $shareLink = ShareLink::where('token', $token)->firstOrFail();
    $asset = $shareLink->asset;
    
    // Log access (guest actor)
    ActivityRecorder::guest(
        tenant: $asset->brand->tenant,
        eventType: EventType::ASSET_SHARED_LINK_ACCESSED,
        subject: $asset,
        metadata: [
            'share_link_id' => $shareLink->id,
            'ip_address' => request()->ip(), // Also captured automatically
        ]
    );
    
    return response()->file($asset->file_path);
}
```

## Example: From Queued Jobs

The ActivityRecorder is safe to use from queued jobs:

```php
use App\Services\ActivityRecorder;
use App\Enums\EventType;

class ProcessAssetUpload implements ShouldQueue
{
    public function handle()
    {
        // Process asset...
        
        // Log system event (no user actor)
        ActivityRecorder::system(
            tenant: $this->asset->brand->tenant,
            eventType: EventType::ASSET_VERSION_ADDED,
            subject: $this->asset,
            metadata: [
                'version' => $this->version,
                'processing_time_ms' => $processingTime,
            ]
        );
    }
}
```

## Example: From Console Commands

```php
use App\Services\ActivityRecorder;
use App\Enums\EventType;

class SyncAssetsCommand extends Command
{
    public function handle()
    {
        foreach ($tenants as $tenant) {
            // Log system event
            ActivityRecorder::system(
                tenant: $tenant,
                eventType: EventType::SYSTEM_INFO,
                subject: null,
                metadata: [
                    'command' => 'sync:assets',
                    'assets_synced' => $count,
                ]
            );
        }
    }
}
```

## Querying Activity Events

### Get events for a tenant

```php
use App\Models\ActivityEvent;

// Recent events for tenant
$events = ActivityEvent::forTenant($tenantId)
    ->recent(50)
    ->get();

// Events for a specific brand
$events = ActivityEvent::forTenant($tenantId)
    ->forBrand($brandId)
    ->recent(50)
    ->get();

// Events for a specific asset
$events = ActivityEvent::forTenant($tenantId)
    ->forSubject(Asset::class, $assetId)
    ->recent(50)
    ->get();

// Events of a specific type
$events = ActivityEvent::forTenant($tenantId)
    ->ofType(EventType::ASSET_DOWNLOAD_CREATED)
    ->recent(50)
    ->get();
```

### Get events with relationships

```php
$events = ActivityEvent::forTenant($tenantId)
    ->with(['actor', 'subject', 'brand'])
    ->recent(50)
    ->get();

foreach ($events as $event) {
    echo $event->actor->name; // User name
    echo $event->subject->name; // Asset name
    echo $event->event_type; // 'asset.uploaded'
    echo $event->metadata['file_size']; // Custom metadata
}
```

## Best Practices

1. **Always provide tenant_id** - Required for all events
2. **Use explicit logging for downloads/previews** - Don't rely on trait
3. **Keep metadata minimal** - Store only essential data, not full payloads
4. **Use EventType constants** - Don't hardcode event type strings
5. **Handle errors gracefully** - Activity logging should never break main operations
6. **Filter by tenant** - Always filter queries by tenant_id at call site
7. **Don't log sensitive data** - Avoid passwords, tokens, etc. in metadata

## Event Type Naming Convention

- Format: `{domain}.{action}` or `{domain}.{category}.{action}`
- Examples:
  - `asset.uploaded`
  - `asset.download.created`
  - `asset.shared.link_accessed`
  - `user.invited`
  - `system.error`

## Disabling Activity Logging

### Per Model Instance

```php
$asset->disableActivityRecording();
$asset->update(['name' => 'New Name']); // Won't log
$asset->enableActivityRecording();
```

### Per Model Class

```php
class Asset extends Model
{
    use RecordsActivity;
    
    protected static $recordActivity = false; // Disable for entire model
}
```

## Migration

Run the migration to create the activity_events table:

```bash
php artisan migrate
```

The table is append-only (no updated_at) and maintains referential integrity with tenants and brands.
