<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Demo\DemoClonePlanService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'demo:plan-clone')]
class DemoPlanCloneCommand extends Command
{
    protected $signature = 'demo:plan-clone
                            {tenant : Template tenant id or slug}
                            {--plan= : Plan key (e.g. pro, starter)}
                            {--expires= : Expiration days (must be in demo.allowed_expiration_days)}
                            {--email=* : Invitee email (repeatable)}
                            {--label= : Target demo label for the future instance}
                            {--json : Output JSON only}';

    protected $description = 'Dry-run demo clone plan from a template tenant (Phase 2B — no writes)';

    public function handle(DemoClonePlanService $planner): int
    {
        $key = (string) $this->argument('tenant');
        $template = is_numeric($key)
            ? Tenant::query()->find($key)
            : Tenant::query()->where('slug', $key)->first();

        if (! $template) {
            $this->error("Tenant not found: {$key}");

            return self::FAILURE;
        }

        $planKey = (string) ($this->option('plan') ?: config('demo.default_plan_key', 'pro'));
        $expires = (int) ($this->option('expires') ?: config('demo.default_expiration_days', 7));
        $emails = (array) $this->option('email');
        $label = (string) ($this->option('label') ?: ($template->demo_label ?: 'Demo instance'));

        try {
            $report = $planner->plan($template, $label, $planKey, $expires, $emails);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $msg) {
                    $this->error("{$field}: {$msg}");
                }
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Clone plan (dry-run) — template #{$report['meta']['template_tenant_id']} {$report['meta']['template_name']}");
        $this->line('Label: '.$report['meta']['target_demo_label']);
        $this->line("Plan: {$report['meta']['plan_key']} ({$report['meta']['plan_display_name']})");
        $this->line('Expires in: '.$report['meta']['expiration_days'].' days (preview '.$report['meta']['demo_expires_at_preview'].')');
        $this->line('Invitees: '.(count($report['meta']['invited_emails']) ? implode(', ', $report['meta']['invited_emails']) : '(none)'));
        $this->newLine();

        $this->components->twoColumnDetail('Estimated clone size', $report['storage_strategy']['estimated_clone_human']);
        $this->line('  '.$report['storage_strategy']['based_on']);
        $this->newLine();

        $this->line('<fg=cyan>Storage strategy</>');
        $this->line('  Recommended: '.$report['storage_strategy']['recommended']);
        $this->line('  '.$report['storage_strategy']['summary']);
        $this->newLine();

        if ($report['blockers'] !== []) {
            $this->warn('Blockers');
            foreach ($report['blockers'] as $b) {
                $this->line('  • '.$b);
            }
            $this->newLine();
        }

        if ($report['warnings'] !== []) {
            $this->warn('Warnings');
            foreach ($report['warnings'] as $w) {
                $this->line('  • '.$w);
            }
        }

        return self::SUCCESS;
    }
}
