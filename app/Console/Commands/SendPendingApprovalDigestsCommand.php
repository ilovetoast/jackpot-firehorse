<?php

namespace App\Console\Commands;

use App\Enums\ApprovalStatus;
use App\Enums\AssetType;
use App\Mail\PendingApprovalsDigestMail;
use App\Models\Asset;
use App\Models\Brand;
use App\Services\ApprovalAgingService;
use App\Services\ApprovalApproverResolver;
use App\Services\FeatureGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPendingApprovalDigestsCommand extends Command
{
    protected $signature = 'approvals:send-pending-digests';

    protected $description = 'Send daily batched emails to approvers for pending team/creator upload approvals (when tenant notification features are on).';

    public function handle(
        FeatureGate $featureGate,
        ApprovalAgingService $agingService,
        ApprovalApproverResolver $approverResolver,
    ): int {
        $brandIds = Asset::query()
            ->where('type', AssetType::ASSET)
            ->where('approval_status', ApprovalStatus::PENDING)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('brand_id');

        $sent = 0;

        foreach ($brandIds as $brandId) {
            $brand = Brand::query()->with('tenant')->find($brandId);
            if (! $brand || ! $brand->tenant) {
                continue;
            }

            $tenant = $brand->tenant;

            if (! $featureGate->approvalsEnabled($tenant) || ! $featureGate->notificationsEnabled($tenant)) {
                continue;
            }

            $pending = Asset::query()
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('type', AssetType::ASSET)
                ->where('approval_status', ApprovalStatus::PENDING)
                ->whereNull('deleted_at')
                ->get();

            if ($pending->isEmpty()) {
                continue;
            }

            $team = $pending->filter(fn (Asset $a) => ! (bool) $a->submitted_by_prostaff);
            $creator = $pending->filter(fn (Asset $a) => (bool) $a->submitted_by_prostaff);

            $teamStats = $this->statsForCollection($team, $agingService);
            $creatorStats = $this->statsForCollection($creator, $agingService);

            if ($teamStats['count'] === 0 && $creatorStats['count'] === 0) {
                continue;
            }

            $approvers = $approverResolver->approversForBrand($brand, null);
            if ($approvers->isEmpty()) {
                continue;
            }

            $teamReviewUrl = url('/app/insights/review?workspace=uploads');
            $creatorReviewUrl = url('/app/insights/creator');

            $dateKey = now()->toDateString();

            foreach ($approvers as $approver) {
                $cacheKey = "approval_digest_sent:{$dateKey}:{$brand->id}:{$approver->id}";
                if (Cache::has($cacheKey)) {
                    continue;
                }

                try {
                    Mail::to($approver->email)->send(
                        new PendingApprovalsDigestMail(
                            $tenant,
                            $brand,
                            $teamStats,
                            $creatorStats,
                            $teamReviewUrl,
                            $creatorReviewUrl,
                        )
                    );
                    Cache::put($cacheKey, true, now()->endOfDay());
                    $sent++;
                } catch (\Throwable $e) {
                    Log::error('[SendPendingApprovalDigestsCommand] Failed to send digest', [
                        'brand_id' => $brand->id,
                        'approver_id' => $approver->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info("Dispatched {$sent} digest message(s).");

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Asset>  $assets
     * @return array{count: int, max_pending_days: int|null, oldest_summary: string|null}
     */
    private function statsForCollection($assets, ApprovalAgingService $agingService): array
    {
        if ($assets->isEmpty()) {
            return ['count' => 0, 'max_pending_days' => null, 'oldest_summary' => null];
        }

        $maxDays = 0;
        foreach ($assets as $asset) {
            $m = $agingService->getAgingMetrics($asset);
            $d = (int) ($m['pending_days'] ?? 0);
            if ($d > $maxDays) {
                $maxDays = $d;
            }
        }

        $count = $assets->count();
        if ($maxDays <= 0) {
            $oldest = 'less than a day';
        } elseif ($maxDays === 1) {
            $oldest = '1 day';
        } else {
            $oldest = "{$maxDays} days";
        }

        return [
            'count' => $count,
            'max_pending_days' => $maxDays,
            'oldest_summary' => $oldest,
        ];
    }
}
