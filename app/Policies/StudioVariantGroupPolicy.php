<?php

namespace App\Policies;

use App\Models\StudioVariantGroup;
use App\Models\User;

class StudioVariantGroupPolicy
{
    public function view(User $user, StudioVariantGroup $group): bool
    {
        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant || ! $brand) {
            return false;
        }

        return (int) $group->tenant_id === (int) $tenant->id
            && (int) $group->brand_id === (int) $brand->id
            && $user->brands()->where('brands.id', $group->brand_id)->exists();
    }
}
