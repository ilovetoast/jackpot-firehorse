<?php

namespace Database\Seeders;

use App\Models\DetectionRule;
use App\Models\TicketCreationRule;
use Illuminate\Database\Seeder;

/**
 * Ticket Creation Rule Seeder
 * 
 * Seeds default ticket creation rules (disabled by default).
 * Rules can be enabled individually via admin or configuration.
 * 
 * 🔒 Phase 5A Step 2 — Automatic Ticket Creation Rules
 */
class TicketCreationRuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all detection rules to create ticket creation rules for them
        $detectionRules = DetectionRule::all();

        foreach ($detectionRules as $detectionRule) {
            // Check if rule already exists (unique constraint: one rule per detection rule)
            $existing = TicketCreationRule::where('rule_id', $detectionRule->id)->first();

            if (!$existing) {
                // Default: Critical alerts auto-create tickets immediately
                // Users can customize min_severity and required_detection_count per rule
                TicketCreationRule::create([
                    'rule_id' => $detectionRule->id,
                    'min_severity' => 'critical', // Default: only critical alerts
                    'required_detection_count' => 1, // Default: create immediately on first detection
                    'auto_create' => true,
                    'enabled' => false, // Disabled by default - must be enabled per rule
                ]);
            }
        }

        // Note: Default rule configuration:
        // - Critical alerts auto-create tickets immediately (min_severity=critical, required_detection_count=1)
        // - Users can change min_severity to 'warning' and required_detection_count to 3+ for warning-based rules
        // - Global-scope critical alerts are handled by critical severity rule with detection_count = 1

        $this->command->info('Ticket creation rules seeded (all disabled by default)');
    }
}
