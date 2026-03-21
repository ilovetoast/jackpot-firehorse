<?php

namespace App\Console\Commands;

use App\Services\AI\Insights\MetadataInsightsAnalyzer;
use Illuminate\Console\Command;

/**
 * Run read-only metadata insights analysis for a tenant (patterns, tags, coverage).
 */
class InsightsMetadataAnalyzeCommand extends Command
{
    protected $signature = 'insights:metadata-analyze
                            {tenant_id : Numeric tenant ID}
                            {--json : Print JSON only (no tables)}';

    protected $description = 'Analyze asset_metadata, asset_metadata_candidates, and asset_tags for patterns (read-only; no AI).';

    public function handle(MetadataInsightsAnalyzer $analyzer): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        if ($tenantId < 1) {
            $this->error('tenant_id must be a positive integer.');

            return self::FAILURE;
        }

        $patterns = $analyzer->analyzeFieldValuePatterns($tenantId);
        $tags = $analyzer->analyzeTagClusters($tenantId);
        $coverage = $analyzer->analyzeFieldCoverage($tenantId);

        $payload = [
            'tenant_id' => $tenantId,
            'field_value_patterns' => $patterns,
            'tag_clusters' => $tags,
            'field_coverage' => $coverage,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Tenant {$tenantId} — metadata insights (read-only)");
        $this->newLine();

        $this->comment('Field value patterns (approved_metadata)');
        $this->table(
            ['field_key', 'top values (value: count)'],
            $this->flattenPatternRows($patterns['approved_metadata'] ?? [])
        );

        $this->comment('Field value patterns (candidates)');
        $this->table(
            ['field_key', 'top values (value: count)'],
            $this->flattenPatternRows($patterns['candidates'] ?? [])
        );

        $this->comment('Tag clusters (ratio = share of total tag rows in tenant)');
        $this->table(
            ['tag', 'count', 'distinct_assets', 'ratio'],
            collect($tags['tags'] ?? [])->take(20)->map(fn ($t) => [
                $t['tag'],
                $t['count'],
                $t['distinct_assets'],
                $t['ratio'],
            ])->all()
        );
        $this->line('total_tag_rows: '.($tags['total_tag_rows'] ?? 0).' | total_assets: '.($tags['total_assets'] ?? 0));
        $this->newLine();

        $this->comment('Field coverage (approved metadata)');
        $this->table(
            ['field_key', 'assets_with_value', 'coverage_ratio'],
            collect($coverage['fields'] ?? [])->take(30)->map(fn ($f) => [
                $f['field_key'],
                $f['assets_with_value'],
                $f['coverage_ratio'],
            ])->all()
        );
        $this->line('total_assets (denominator): '.($coverage['total_assets'] ?? 0));

        return self::SUCCESS;
    }

    /**
     * @param  list<array{field_key: string, values: list<array{value: string, count: int}>}>  $rows
     * @return list<array{0: string, 1: string}>
     */
    protected function flattenPatternRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $top = array_slice($row['values'] ?? [], 0, 5);
            $summary = collect($top)->map(fn ($v) => ($v['value'] === '' ? '(empty)' : $v['value']).': '.$v['count'])->implode('; ');
            $out[] = [$row['field_key'], $summary];
        }

        return $out;
    }
}
