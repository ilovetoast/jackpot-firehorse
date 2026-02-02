<?php

namespace Database\Seeders;

use App\Models\AgencyTier;
use Illuminate\Database\Seeder;

class AgencyTierSeeder extends Seeder
{
    /**
     * Seed the agency tiers.
     * 
     * Phase AG-6: Added default configuration values.
     */
    public function run(): void
    {
        $tiers = [
            [
                'name' => 'Silver',
                'tier_order' => 1,
                'activation_threshold' => 0,
                'reward_percentage' => null, // Not used yet
                'max_incubated_companies' => null, // Not enforced yet
                'max_incubated_brands' => null, // Not enforced yet
                'incubation_window_days' => null, // Not enforced yet
            ],
            [
                'name' => 'Gold',
                'tier_order' => 2,
                'activation_threshold' => 5,
                'reward_percentage' => null,
                'max_incubated_companies' => null,
                'max_incubated_brands' => null,
                'incubation_window_days' => null,
            ],
            [
                'name' => 'Platinum',
                'tier_order' => 3,
                'activation_threshold' => 15,
                'reward_percentage' => null,
                'max_incubated_companies' => null,
                'max_incubated_brands' => null,
                'incubation_window_days' => null,
            ],
        ];

        foreach ($tiers as $tier) {
            AgencyTier::updateOrCreate(
                ['name' => $tier['name']],
                $tier
            );
        }
    }
}
