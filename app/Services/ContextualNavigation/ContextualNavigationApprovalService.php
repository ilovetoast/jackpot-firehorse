<?php

namespace App\Services\ContextualNavigation;

use App\Models\Category;
use App\Models\ContextualNavigationRecommendation;
use App\Models\MetadataField;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Filters\FolderQuickFilterAssignmentService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phase 6 — admin approval router.
 *
 * Approving a recommendation NEVER mutates `metadata_field_visibility`
 * directly. It always delegates to FolderQuickFilterAssignmentService so
 * Phase 5.x conventions (sort orders, source provenance, pinned flags)
 * stay consistent.
 *
 * Reject / defer just stamp the row. Stale-resolution is handled by
 * ContextualNavigationStaleResolver.
 */
class ContextualNavigationApprovalService
{
    public function __construct(
        protected FolderQuickFilterAssignmentService $assignment,
    ) {}

    /**
     * Approve and apply the action implied by `recommendation_type`.
     *
     * @throws InvalidArgumentException if the recommendation is not
     *         actionable (warnings) or already finalised.
     */
    public function approve(
        ContextualNavigationRecommendation $rec,
        Tenant $tenant,
        User $user,
        ?string $notes = null,
    ): ContextualNavigationRecommendation {
        $this->assertSameTenant($rec, $tenant);
        $this->assertPending($rec);
        if (! $rec->isActionable()) {
            throw new InvalidArgumentException(
                'Warning recommendations are informational only and cannot be approved.'
            );
        }
        if ($rec->category_id === null || $rec->metadata_field_id === null) {
            throw new InvalidArgumentException('Recommendation is missing folder or field context.');
        }

        $folder = Category::query()->where('id', $rec->category_id)->where('tenant_id', $tenant->id)->first();
        if (! $folder) {
            // Folder vanished mid-review — mark stale and bail.
            $rec->update(['status' => ContextualNavigationRecommendation::STATUS_STALE]);
            throw new InvalidArgumentException('Folder no longer exists for this recommendation.');
        }
        $field = MetadataField::query()->find($rec->metadata_field_id);
        if (! $field) {
            $rec->update(['status' => ContextualNavigationRecommendation::STATUS_STALE]);
            throw new InvalidArgumentException('Metadata field no longer exists for this recommendation.');
        }

        DB::transaction(function () use ($rec, $folder, $field, $user, $notes) {
            switch ($rec->recommendation_type) {
                case ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER:
                    // Source = ai_suggested so the audit on the visibility
                    // row reflects the recommender's decision provenance.
                    // Phase 5.2 reserved this string for exactly this case.
                    $this->assignment->enableQuickFilter($folder, $field, [
                        'source' => 'ai_suggested',
                    ]);
                    break;

                case ContextualNavigationRecommendation::TYPE_SUGGEST_PIN:
                    // Pin only takes effect on already-enabled rows; the
                    // recommender only emits this for already-enabled
                    // filters, but we double-enable here to be defensive
                    // in case state drifted between recommendation and
                    // approval.
                    $this->assignment->enableQuickFilter($folder, $field, [
                        'pinned' => true,
                        'source' => 'ai_suggested',
                    ]);
                    break;

                case ContextualNavigationRecommendation::TYPE_SUGGEST_UNPIN:
                    $this->assignment->setQuickFilterPinned($folder, $field, false);
                    break;

                case ContextualNavigationRecommendation::TYPE_SUGGEST_DISABLE:
                    $this->assignment->disableQuickFilter($folder, $field);
                    break;

                case ContextualNavigationRecommendation::TYPE_SUGGEST_OVERFLOW:
                    // No first-class "overflow" state — overflow is the
                    // natural consequence of `max_visible_per_folder` +
                    // sort. Best we can do without disabling: bump weight
                    // down so the filter falls below the visible cap.
                    $this->assignment->updateQuickFilterWeight($folder, $field, 9999);
                    break;

                default:
                    throw new InvalidArgumentException(
                        "Unsupported actionable type: {$rec->recommendation_type}"
                    );
            }

            $rec->update([
                'status' => ContextualNavigationRecommendation::STATUS_ACCEPTED,
                'reviewed_by_user_id' => $user->id,
                'reviewed_at' => now(),
                'reviewer_notes' => $notes,
            ]);
        });

        return $rec->fresh();
    }

    public function reject(
        ContextualNavigationRecommendation $rec,
        Tenant $tenant,
        User $user,
        ?string $notes = null,
    ): ContextualNavigationRecommendation {
        $this->assertSameTenant($rec, $tenant);
        $this->assertPending($rec);
        $rec->update([
            'status' => ContextualNavigationRecommendation::STATUS_REJECTED,
            'reviewed_by_user_id' => $user->id,
            'reviewed_at' => now(),
            'reviewer_notes' => $notes,
        ]);

        return $rec->fresh();
    }

    public function defer(
        ContextualNavigationRecommendation $rec,
        Tenant $tenant,
        User $user,
        ?string $notes = null,
    ): ContextualNavigationRecommendation {
        $this->assertSameTenant($rec, $tenant);
        $this->assertPending($rec);
        $rec->update([
            'status' => ContextualNavigationRecommendation::STATUS_DEFERRED,
            'reviewed_by_user_id' => $user->id,
            'reviewed_at' => now(),
            'reviewer_notes' => $notes,
        ]);

        return $rec->fresh();
    }

    private function assertSameTenant(ContextualNavigationRecommendation $rec, Tenant $tenant): void
    {
        if ((int) $rec->tenant_id !== (int) $tenant->id) {
            throw new InvalidArgumentException('Recommendation does not belong to this tenant.');
        }
    }

    private function assertPending(ContextualNavigationRecommendation $rec): void
    {
        if (! in_array($rec->status, [
            ContextualNavigationRecommendation::STATUS_PENDING,
            ContextualNavigationRecommendation::STATUS_DEFERRED,
        ], true)) {
            throw new InvalidArgumentException(
                "Recommendation is already {$rec->status}; cannot act on it."
            );
        }
    }
}
