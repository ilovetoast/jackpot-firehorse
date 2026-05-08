<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Demo\DemoTemplateAuditService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'demo:audit-template')]
class DemoAuditTemplateCommand extends Command
{
    protected $signature = 'demo:audit-template {tenant : Tenant id or slug} {--json : Output JSON only}';

    protected $description = 'Audit a demo template tenant (Phase 2A — read-only, no cloning)';

    public function handle(DemoTemplateAuditService $auditService): int
    {
        $key = (string) $this->argument('tenant');
        $tenant = is_numeric($key)
            ? Tenant::query()->find($key)
            : Tenant::query()->where('slug', $key)->first();

        if (! $tenant) {
            $this->error("Tenant not found: {$key}");

            return self::FAILURE;
        }

        try {
            $report = $auditService->audit($tenant);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Demo template audit — {$report['meta']['tenant_name']} (#{$report['meta']['tenant_id']})");
        $this->line('Audited at: '.$report['meta']['audited_at']);
        $this->newLine();

        $this->components->twoColumnDetail('Clone-ready (counts)', '');
        foreach ($report['clone_ready'] as $label => $count) {
            $this->line(sprintf('  %-40s %s', str_replace('_', ' ', $label), $count));
        }

        $this->newLine();
        $this->components->twoColumnDetail('Excluded from clone', '');
        foreach ($report['excluded_from_clone'] as $label => $count) {
            $this->line(sprintf('  %-40s %s', str_replace('_', ' ', $label), $count));
        }

        $this->newLine();
        $this->components->twoColumnDetail('Storage', '');
        foreach ($report['storage'] as $k => $v) {
            $this->line(sprintf('  %-40s %s', $k, $v === null ? '—' : $v));
        }

        foreach (['warnings', 'unsupported_relationships', 'missing_required_data'] as $section) {
            $rows = $report[$section] ?? [];
            if ($rows === []) {
                continue;
            }
            $this->newLine();
            $this->warn(strtoupper(str_replace('_', ' ', $section)));
            foreach ($rows as $line) {
                $this->line('  • '.$line);
            }
        }

        return self::SUCCESS;
    }
}
