<?php

namespace App\Support;

/**
 * Delivery context enum.
 *
 * Determines how asset URLs are generated:
 * - AUTHENTICATED: Signed cookies apply; return plain CDN URL
 * - PUBLIC_COLLECTION: Public collection page; generate signed URL with collection TTL
 * - PUBLIC_DOWNLOAD: Public download landing; generate signed URL with download expiration policy
 */
enum DeliveryContext: string
{
    case AUTHENTICATED = 'authenticated';
    case PUBLIC_COLLECTION = 'public_collection';
    case PUBLIC_DOWNLOAD = 'public_download';
}
