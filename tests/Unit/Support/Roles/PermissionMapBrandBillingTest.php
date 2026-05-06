<?php

namespace Tests\Unit\Support\Roles;

use App\Support\Roles\PermissionMap;
use PHPUnit\Framework\TestCase;

class PermissionMapBrandBillingTest extends TestCase
{
    public function test_brand_roles_do_not_grant_billing_view(): void
    {
        foreach (array_keys(PermissionMap::brandPermissions()) as $role) {
            $perms = PermissionMap::getBrandRolePermissions($role);
            $this->assertNotContains(
                'billing.view',
                $perms,
                "Brand role \"{$role}\" must not include billing.view (tenant-scoped only)."
            );
        }
    }
}
