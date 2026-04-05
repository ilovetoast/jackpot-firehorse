<?php

namespace App\Services\Prostaff;

use App\Models\ProstaffMembership;

class RemoveProstaffMember
{
    public function remove(ProstaffMembership $membership): void
    {
        $membership->status = 'removed';
        $membership->ended_at = now();
        $membership->save();

        $user = $membership->user;
        if ($user !== null) {
            $brand = $membership->brand;
            if ($brand !== null) {
                $user->forgetActiveBrandMembershipForBrand($brand);
            }
        }
    }
}
