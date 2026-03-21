<?php

namespace App\Console\Commands;

use App\Services\AI\Insights\FieldSuggestionEngine;
use Illuminate\Console\Command;

class InsightsSyncFieldSuggestionsCommand extends Command
{
    protected $signature = 'insights:sync-field-suggestions {tenant_id : Tenant ID}';

    protected $description = 'Generate ai_metadata_field_suggestions rows from per-category tag clusters (no metadata_fields rows).';

    public function handle(FieldSuggestionEngine $engine): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        if ($tenantId < 1) {
            $this->error('tenant_id must be a positive integer.');

            return self::FAILURE;
        }

        $n = $engine->sync($tenantId);
        $this->info("Inserted {$n} new field suggestion row(s) for tenant {$tenantId}.");

        return self::SUCCESS;
    }
}
