<?php

namespace App\Console\Commands;

use App\Services\AI\Insights\ValueSuggestionEngine;
use Illuminate\Console\Command;

class InsightsSyncValueSuggestionsCommand extends Command
{
    protected $signature = 'insights:sync-value-suggestions {tenant_id : Tenant ID}';

    protected $description = 'Generate ai_metadata_value_suggestions rows from tags and metadata patterns (no AI calls).';

    public function handle(ValueSuggestionEngine $engine): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        if ($tenantId < 1) {
            $this->error('tenant_id must be a positive integer.');

            return self::FAILURE;
        }

        $n = $engine->sync($tenantId);
        $this->info("Inserted {$n} new suggestion row(s) for tenant {$tenantId}.");

        return self::SUCCESS;
    }
}
