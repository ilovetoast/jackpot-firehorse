<?php

namespace App\Services\Prostaff;

use App\Models\Brand;
use App\Models\BrandInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * When a {@see BrandInvitation} is accepted, optionally assign prostaff from invitation metadata.
 * Failures are logged and never thrown — invite acceptance must always succeed.
 */
final class ApplyProstaffAfterBrandInvitationAccept
{
    /**
     * Apply prostaff assignment if metadata requests it. Safe to call after tenant + brand membership exist.
     */
    public function apply(User $user, BrandInvitation $invitation, Brand $brand): void
    {
        $meta = $invitation->metadata ?? [];
        $flag = $meta['assign_prostaff_after_accept'] ?? false;
        if (! ($flag === true || $flag === 1 || $flag === '1')) {
            return;
        }

        if ((int) $invitation->brand_id !== (int) $brand->id) {
            Log::warning('prostaff.invite.apply_skipped_brand_mismatch', [
                'user_id' => $user->id,
                'brand_id' => $brand->id,
                'invitation_id' => $invitation->id,
                'invitation_brand_id' => $invitation->brand_id,
            ]);

            return;
        }

        $tenant = $brand->tenant;
        if ($tenant === null) {
            Log::warning('prostaff.invite.apply_skipped_no_tenant', [
                'user_id' => $user->id,
                'brand_id' => $brand->id,
                'invitation_id' => $invitation->id,
            ]);

            return;
        }

        try {
            app(EnsureCreatorModuleEnabled::class)->assertEnabled($tenant);
        } catch (\Throwable $e) {
            Log::warning('prostaff.invitation_skip_module_inactive', [
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'invitation_id' => $invitation->id,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $targetRaw = $meta['prostaff_target_uploads'] ?? $meta['target_uploads'] ?? null;
        $target = ($targetRaw !== null && $targetRaw !== '' && is_numeric($targetRaw))
            ? (int) $targetRaw
            : null;

        $periodType = $meta['prostaff_period_type'] ?? $meta['period_type'] ?? 'month';
        if (! is_string($periodType) || $periodType === '') {
            $periodType = 'month';
        }

        try {
            app(AssignProstaffMember::class)->assign($user, $brand, [
                'target_uploads' => $target,
                'period_type' => $periodType,
                'assigned_by_user_id' => $invitation->invited_by,
            ]);

            Log::info('prostaff.invite.applied', [
                'user_id' => $user->id,
                'brand_id' => $brand->id,
                'invitation_id' => $invitation->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('prostaff.invite.apply_failed', [
                'user_id' => $user->id,
                'brand_id' => $brand->id,
                'invitation_id' => $invitation->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
