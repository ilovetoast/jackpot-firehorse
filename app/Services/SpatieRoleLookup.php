<?php

namespace App\Services;

use Spatie\Permission\Models\Role;

/**
 * Request-scoped memoization for Spatie {@see Role} lookups by name + guard.
 *
 * {@see User::hasPermissionForTenant} and {@see User::hasPermissionForBrand} previously
 * queried `roles` once per permission check. {@see AuthPermissionService::effectivePermissions}
 * loops all permissions, producing dozens of identical queries per request.
 */
final class SpatieRoleLookup
{
    /** @var array<string, Role|null> */
    private array $cache = [];

    public function roleByName(string $name, string $guard = 'web'): ?Role
    {
        $key = $guard.'|'.$name;
        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = Role::query()
                ->where('name', $name)
                ->where('guard_name', $guard)
                ->first();
        }

        return $this->cache[$key];
    }
}
