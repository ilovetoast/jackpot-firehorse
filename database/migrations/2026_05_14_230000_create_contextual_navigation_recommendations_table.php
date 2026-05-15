<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — Contextual Navigation Intelligence.
 *
 * Reviewable recommendations for folder × quick-filter configuration. We
 * intentionally do NOT extend `ai_metadata_value_suggestions` /
 * `ai_metadata_field_suggestions` — those tables are dropdown-option /
 * new-field semantics, while this table is folder × field × visibility/
 * pin/overflow semantics.
 *
 * Lifecycle:
 *   created_at → status='pending'
 *   admin acts → status='accepted' | 'rejected' | 'deferred'
 *   stale resolver / TTL → status='stale' (only from pending/deferred)
 *
 * Idempotence:
 *   ContextualNavigationRecommender uses a soft-upsert keyed by
 *   (tenant, brand, category, field, type) so re-running the job replaces
 *   the latest pending row instead of stacking duplicates. The
 *   `last_seen_at` column lets the stale resolver recognise "we still
 *   surface this" vs "the signal disappeared".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contextual_navigation_recommendations')) {
            return;
        }

        Schema::create('contextual_navigation_recommendations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('brand_id')->nullable();
            // category_id is the folder. Nullable so future tenant-wide
            // recommendations (e.g. "Subject is too fragmented across the
            // whole tenant") can land in the same table.
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('metadata_field_id')->nullable();

            // One of the 11 recommendation types defined in
            // ContextualNavigationRecommendation::TYPE_*. String column
            // (not enum) so we can add types without a migration.
            $table->string('recommendation_type', 64);

            // 'statistical' | 'ai' | 'hybrid'
            $table->string('source', 16)->default('statistical');

            // 'pending' | 'accepted' | 'rejected' | 'deferred' | 'stale'
            $table->string('status', 16)->default('pending');

            // 0.0 — 1.0 normalized score from
            // ContextualNavigationScoringService::computeOverallScore.
            $table->decimal('score', 5, 4)->nullable();

            // Optional independent confidence (different from score):
            // for AI-enriched rows, this is the model's expressed
            // confidence; for statistical rows it mirrors `score`.
            $table->decimal('confidence', 5, 4)->nullable();

            // One-line human-readable summary for list rows. Long-form
            // rationale lives in `metrics->rationale` to keep the column
            // small for index scans.
            $table->string('reason_summary', 512)->nullable();

            // Free-form supporting JSON. Includes:
            //   - per-signal sub-scores (coverage / narrowing / fragmentation / usage)
            //   - raw counters used for the scoring run
            //   - rationale string when AI reasoning is enabled
            //   - manage_url + insights_url deep links (computed at render)
            $table->json('metrics')->nullable();

            // Approval audit
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            // Free-form admin note on approve/reject/defer.
            $table->text('reviewer_notes')->nullable();

            // Last time the recommender saw the underlying signal still
            // pointing this way. Stale resolver uses this + TTL.
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            // Hot read paths
            $table->index(
                ['tenant_id', 'status', 'recommendation_type'],
                'cnr_tenant_status_type_idx'
            );
            $table->index(
                ['tenant_id', 'category_id', 'status'],
                'cnr_tenant_category_status_idx'
            );
            $table->index(
                ['tenant_id', 'metadata_field_id', 'status'],
                'cnr_tenant_field_status_idx'
            );
            // Idempotence upsert key (NOT unique — two pending rows of
            // different types are valid; the recommender enforces single
            // pending row per type via its own logic).
            $table->index(
                ['tenant_id', 'category_id', 'metadata_field_id', 'recommendation_type'],
                'cnr_upsert_key_idx'
            );

            // Foreign keys — every parent cascades / nulls to keep rows
            // from accumulating after admin deletes the underlying object.
            //
            // Explicit short constraint names: MySQL identifier max length is 64.
            // Laravel's default `…_reviewed_by_user_id_foreign` on this table name exceeds it.
            $table->foreign('tenant_id', 'cnr_tenant_fk')
                ->references('id')->on('tenants')
                ->cascadeOnDelete();
            $table->foreign('brand_id', 'cnr_brand_fk')
                ->references('id')->on('brands')
                ->nullOnDelete();
            $table->foreign('category_id', 'cnr_category_fk')
                ->references('id')->on('categories')
                ->cascadeOnDelete();
            $table->foreign('metadata_field_id', 'cnr_metadata_field_fk')
                ->references('id')->on('metadata_fields')
                ->cascadeOnDelete();
            $table->foreign('reviewed_by_user_id', 'cnr_reviewed_by_user_fk')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contextual_navigation_recommendations');
    }
};
