<?php

namespace App\Services\Prostaff;

use App\Models\Brand;
use Illuminate\Validation\ValidationException;

/**
 * Creator managers must assign at least one approver before adding creators.
 */
final class EnsureCreatorApproversConfigured
{
    public function assert(Brand $brand): void
    {
        if ($brand->hasConfiguredCreatorApprovers()) {
            return;
        }

        throw ValidationException::withMessages([
            'approvers' => ['Assign at least one creator approver in Brand Settings → Creators before adding creators.'],
        ]);
    }
}
