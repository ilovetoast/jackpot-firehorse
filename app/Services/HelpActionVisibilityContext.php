<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;

/**
 * Workspace context for help action visibility (permissions are evaluated separately).
 */
final class HelpActionVisibilityContext
{
    public function __construct(
        public User $user,
        public Tenant $tenant,
        public ?Brand $brand,
    ) {}
}
