<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\StudioAnimationJob;
use App\Models\User;

class StudioAnimationJobPolicy
{
    public function view(User $user, StudioAnimationJob $job): bool
    {
        $brand = app()->bound('brand') ? app('brand') : null;
        if (! $brand instanceof Brand || (int) $brand->id !== (int) $job->brand_id) {
            return false;
        }

        return $user->hasPermissionForBrand($brand, 'asset.upload');
    }

    public function create(User $user): bool
    {
        $brand = app()->bound('brand') ? app('brand') : null;
        if (! $brand instanceof Brand) {
            return false;
        }

        return $user->hasPermissionForBrand($brand, 'asset.upload');
    }

    public function retry(User $user, StudioAnimationJob $job): bool
    {
        return $this->view($user, $job) && $job->status === \App\Studio\Animation\Enums\StudioAnimationStatus::Failed->value;
    }

    public function cancel(User $user, StudioAnimationJob $job): bool
    {
        if (! $this->view($user, $job)) {
            return false;
        }

        return in_array($job->status, [
            \App\Studio\Animation\Enums\StudioAnimationStatus::Queued->value,
            \App\Studio\Animation\Enums\StudioAnimationStatus::Rendering->value,
            \App\Studio\Animation\Enums\StudioAnimationStatus::Submitting->value,
            \App\Studio\Animation\Enums\StudioAnimationStatus::Processing->value,
            \App\Studio\Animation\Enums\StudioAnimationStatus::Downloading->value,
            \App\Studio\Animation\Enums\StudioAnimationStatus::Finalizing->value,
        ], true);
    }

    /**
     * Remove a terminal failed/canceled job from the Versions rail (DB row + cascaded pipeline rows only).
     */
    public function delete(User $user, StudioAnimationJob $job): bool
    {
        if (! $this->view($user, $job)) {
            return false;
        }

        return in_array($job->status, [
            \App\Studio\Animation\Enums\StudioAnimationStatus::Failed->value,
            \App\Studio\Animation\Enums\StudioAnimationStatus::Canceled->value,
        ], true);
    }
}
