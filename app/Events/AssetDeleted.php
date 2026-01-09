<?php

namespace App\Events;

use App\Models\Asset;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AssetDeleted Event
 *
 * Domain event emitted when an asset is permanently deleted from storage.
 * This event is fired after successful S3 deletion and confirmation.
 *
 * Future Phase: Listeners can subscribe to this event for cleanup,
 * analytics, or audit logging.
 */
class AssetDeleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param Asset $asset The asset that was deleted (before deletion from database)
     * @param array $deletedPaths Array of S3 paths that were deleted
     */
    public function __construct(
        public Asset $asset,
        public array $deletedPaths
    ) {
    }
}
