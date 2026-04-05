<?php

namespace App\Services\Prostaff;

use App\Models\Brand;
use App\Models\User;
use App\Services\ApprovalNotificationService;
use Illuminate\Support\Collection;

class ResolveProstaffBatchNotificationRecipients
{
    public function resolve(Brand $brand, ?User $prostaffUploader): Collection
    {
        $tenant = $brand->tenant;
        if ($tenant === null) {
            return collect();
        }

        $permission = (string) config('prostaff.batch_notification_permission', 'brand.prostaff.approve');

        $specific = collect();
        foreach ($tenant->users as $user) {
            if (! $user->activeBrandMembership($brand)) {
                continue;
            }

            if (
                $user->hasPermissionForBrand($brand, $permission)
                || $user->hasPermissionForTenant($tenant, $permission)
            ) {
                $specific->push($user);
            }
        }

        $specific = $specific->unique('id');

        if ($prostaffUploader !== null) {
            $specific = $specific->reject(fn (User $u) => $u->id === $prostaffUploader->id);
        }

        if ($specific->isNotEmpty()) {
            return $specific;
        }

        $fallback = app(ApprovalNotificationService::class)->approvalCapableRecipientsForBrand($brand);

        if ($prostaffUploader !== null) {
            $fallback = $fallback->reject(fn (User $u) => $u->id === $prostaffUploader->id);
        }

        return $fallback;
    }
}
