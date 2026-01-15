<?php

namespace App\Enums;

/**
 * Download type enum.
 *
 * Distinguishes between snapshot and living download groups.
 */
enum DownloadType: string
{
    /**
     * Snapshot download: Asset list is frozen at creation time.
     * Immutable asset list - assets cannot be added or removed after creation.
     * ZIP represents exact state at creation time.
     */
    case SNAPSHOT = 'snapshot';

    /**
     * Living download: Asset list can change over time.
     * Mutable asset list - assets can be added or removed.
     * ZIP must be regenerated when assets change.
     */
    case LIVING = 'living';
}
