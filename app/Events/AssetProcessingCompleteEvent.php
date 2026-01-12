<?php

namespace App\Events;

use App\Models\Asset;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AssetProcessingCompleteEvent
 *
 * Domain event emitted when asset processing is complete.
 * This event is fired after thumbnail generation and metadata extraction jobs have been dispatched.
 */
class AssetProcessingCompleteEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param Asset $asset The asset that processing is complete for
     */
    public function __construct(
        public Asset $asset
    ) {
    }
}
