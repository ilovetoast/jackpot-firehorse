<?php

namespace App\Events;

use App\Models\Asset;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AssetUploaded Event
 *
 * Domain event emitted when an asset upload is completed and the asset record is created.
 * This event is fired after successful S3 verification and Asset creation.
 *
 * Future Phase: Processing jobs will listen to this event to start async processing
 * (transcoding, thumbnail generation, metadata extraction, etc.).
 */
class AssetUploaded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param Asset $asset The newly created asset
     */
    public function __construct(
        public Asset $asset
    ) {
    }
}
