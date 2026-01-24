<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AI Tag Auto-Apply Service
 *
 * Phase J.2.2: Auto-application of high-confidence AI tags
 * 
 * Handles automatic application of AI tag candidates based on tenant policy,
 * with proper normalization, limits, and full reversibility.
 */
class AiTagAutoApplyService
{
    protected TagNormalizationService $normalizationService;
    protected AiTagPolicyService $policyService;

    public function __construct(
        TagNormalizationService $normalizationService,
        AiTagPolicyService $policyService
    ) {
        $this->normalizationService = $normalizationService;
        $this->policyService = $policyService;
    }

    /**
     * Process auto-apply for an asset's tag candidates.
     *
     * @param Asset $asset The asset to process
     * @return array Results of auto-apply operation
     */
    public function processAutoApply(Asset $asset): array
    {
        $tenant = Tenant::find($asset->tenant_id);
        
        if (!$tenant) {
            return [
                'auto_applied' => 0,
                'skipped' => 0,
                'reason' => 'tenant_not_found',
            ];
        }

        // Check if auto-apply is enabled for this tenant
        if (!$this->policyService->isAiTagAutoApplyEnabled($tenant)) {
            return [
                'auto_applied' => 0,
                'skipped' => 0,
                'reason' => 'auto_apply_disabled',
            ];
        }

        // Get all unresolved, non-dismissed tag candidates
        $candidates = DB::table('asset_tag_candidates')
            ->where('asset_id', $asset->id)
            ->where('producer', 'ai')
            ->whereNull('resolved_at')
            ->whereNull('dismissed_at')
            ->get()
            ->toArray();

        if (empty($candidates)) {
            return [
                'auto_applied' => 0,
                'skipped' => 0,
                'reason' => 'no_candidates',
            ];
        }

        // Convert candidates to format expected by policy service
        $candidatesForPolicy = array_map(function ($candidate) {
            return [
                'id' => $candidate->id,
                'tag' => $candidate->tag,
                'confidence' => $candidate->confidence,
                'source' => $candidate->source,
            ];
        }, $candidates);

        // Use policy service to select tags for auto-apply (respects limits)
        $selectedCandidates = $this->policyService->selectTagsForAutoApply($asset, $candidatesForPolicy);

        if (empty($selectedCandidates)) {
            return [
                'auto_applied' => 0,
                'skipped' => count($candidates),
                'reason' => 'none_selected_by_policy',
            ];
        }

        $autoApplied = 0;
        $skipped = 0;
        $errors = [];

        foreach ($selectedCandidates as $candidate) {
            try {
                $result = $this->autoApplyCandidate($asset, $candidate, $tenant);
                if ($result['success']) {
                    $autoApplied++;
                } else {
                    $skipped++;
                    $errors[] = $result['reason'];
                }
            } catch (\Exception $e) {
                $skipped++;
                $errors[] = $e->getMessage();
                Log::error('[AiTagAutoApplyService] Failed to auto-apply candidate', [
                    'asset_id' => $asset->id,
                    'candidate_id' => $candidate['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $totalCandidates = count($candidates);
        $remainingSkipped = $totalCandidates - count($selectedCandidates);

        Log::info('[AiTagAutoApplyService] Auto-apply completed', [
            'asset_id' => $asset->id,
            'tenant_id' => $tenant->id,
            'total_candidates' => $totalCandidates,
            'selected_for_auto_apply' => count($selectedCandidates),
            'auto_applied' => $autoApplied,
            'skipped' => $skipped + $remainingSkipped,
            'errors' => $errors,
        ]);

        return [
            'auto_applied' => $autoApplied,
            'skipped' => $skipped + $remainingSkipped,
            'total_candidates' => $totalCandidates,
            'selected_candidates' => count($selectedCandidates),
            'errors' => $errors,
        ];
    }

    /**
     * Auto-apply a single tag candidate.
     *
     * @param Asset $asset The asset
     * @param array $candidate The candidate to auto-apply
     * @param Tenant $tenant The tenant
     * @return array Result of the operation
     */
    protected function autoApplyCandidate(Asset $asset, array $candidate, Tenant $tenant): array
    {
        // Normalize the tag to canonical form
        $canonicalTag = $this->normalizationService->normalize($candidate['tag'], $tenant);

        if ($canonicalTag === null) {
            return [
                'success' => false,
                'reason' => 'normalization_failed_or_blocked',
            ];
        }

        // Check if canonical tag already exists
        $existingTag = DB::table('asset_tags')
            ->where('asset_id', $asset->id)
            ->where('tag', $canonicalTag)
            ->first();

        if ($existingTag) {
            // Mark candidate as resolved but don't create duplicate
            DB::table('asset_tag_candidates')
                ->where('id', $candidate['id'])
                ->update([
                    'resolved_at' => now(),
                    'updated_at' => now(),
                ]);

            return [
                'success' => false,
                'reason' => 'duplicate_canonical_tag_exists',
            ];
        }

        // Apply the tag with auto-apply source
        DB::transaction(function () use ($asset, $candidate, $canonicalTag) {
            // Create the auto-applied tag
            DB::table('asset_tags')->insert([
                'asset_id' => $asset->id,
                'tag' => $canonicalTag,
                'source' => 'ai:auto', // Special source indicating auto-application
                'confidence' => $candidate['confidence'],
                'created_at' => now(),
            ]);

            // Mark candidate as resolved
            DB::table('asset_tag_candidates')
                ->where('id', $candidate['id'])
                ->update([
                    'resolved_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return [
            'success' => true,
            'canonical_tag' => $canonicalTag,
            'original_tag' => $candidate['tag'],
        ];
    }

    /**
     * Remove an auto-applied tag (for user reversibility).
     *
     * @param Asset $asset The asset
     * @param string $tag The canonical tag to remove
     * @return bool True if tag was removed
     */
    public function removeAutoAppliedTag(Asset $asset, string $tag): bool
    {
        $deleted = DB::table('asset_tags')
            ->where('asset_id', $asset->id)
            ->where('tag', $tag)
            ->where('source', 'ai:auto') // Only remove auto-applied tags
            ->delete();

        if ($deleted > 0) {
            Log::info('[AiTagAutoApplyService] Auto-applied tag removed', [
                'asset_id' => $asset->id,
                'tag' => $tag,
            ]);
            return true;
        }

        return false;
    }

    /**
     * Get all auto-applied tags for an asset.
     *
     * @param Asset $asset The asset
     * @return array Auto-applied tags
     */
    public function getAutoAppliedTags(Asset $asset): array
    {
        return DB::table('asset_tags')
            ->where('asset_id', $asset->id)
            ->where('source', 'ai:auto')
            ->orderBy('created_at')
            ->get()
            ->toArray();
    }

    /**
     * Check if auto-apply should run for an asset.
     *
     * @param Asset $asset The asset
     * @return bool True if auto-apply should be processed
     */
    public function shouldProcessAutoApply(Asset $asset): bool
    {
        $tenant = Tenant::find($asset->tenant_id);
        
        if (!$tenant) {
            return false;
        }

        // Check if auto-apply is enabled
        if (!$this->policyService->isAiTagAutoApplyEnabled($tenant)) {
            return false;
        }

        // Check if there are any unresolved candidates
        $hasCandidates = DB::table('asset_tag_candidates')
            ->where('asset_id', $asset->id)
            ->where('producer', 'ai')
            ->whereNull('resolved_at')
            ->whereNull('dismissed_at')
            ->exists();

        return $hasCandidates;
    }
}