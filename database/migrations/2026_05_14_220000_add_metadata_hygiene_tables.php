<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.3 — metadata hygiene infrastructure.
 *
 * Two additive tables; nothing existing is modified.
 *
 *   `metadata_value_aliases` — alias → canonical mapping per (tenant, field).
 *     The hygiene runtime resolves alias values to their canonical when an
 *     admin asks "what should this value really be?". Phase 5.3 surfaces
 *     this passively (the assignment / value endpoints expose aliases as
 *     metadata, but the global filter engine still matches by raw value).
 *     Phase 6+ may opt-in alias-aware filtering.
 *
 *     Loop / chain guards live in MetadataCanonicalizationService — the DB
 *     just enforces uniqueness on the alias side. `normalization_hash` is
 *     denormalized so duplicate-detection scans don't have to recompute the
 *     normalizer for every option on every pass.
 *
 *   `metadata_value_merges` — append-only audit row per merge action. Lives
 *     in the DB so the admin "merge history" surface (future) does not need
 *     a separate analytics store. Truncating this table is safe — it carries
 *     no functional state.
 *
 * Design notes:
 *   - All scoping is per-tenant. Aliases are NOT global; "Outdoor" can mean
 *     different things in different tenants and the hygiene system never
 *     leaks across tenant boundaries.
 *   - Indexes pick the two read paths: (tenant, field, alias_value) for
 *     "is this value an alias of something?" and (tenant, field,
 *     normalization_hash) for duplicate clustering.
 *   - `source` is constrained at the application layer (see
 *     MetadataCanonicalizationService::SOURCE_*) so the column stays
 *     forward-compatible without a CHECK constraint admins cannot extend.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('metadata_value_aliases')) {
            Schema::create('metadata_value_aliases', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('metadata_field_id');
                $table->string('alias_value', 255);
                $table->string('canonical_value', 255);
                $table->string('normalization_hash', 32);
                $table->string('source', 32)->default('manual');
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                // Alias is unique within (tenant, field) — one canonical per
                // alias; admins must explicitly remove an alias before it
                // can repoint somewhere else.
                $table->unique(
                    ['tenant_id', 'metadata_field_id', 'alias_value'],
                    'mva_alias_unique_idx'
                );
                // Hot read path: "what's the canonical for this value?" The
                // unique index above already covers (tenant, field, alias),
                // so this index targets the secondary lookup of "list all
                // aliases pointing at canonical X".
                $table->index(
                    ['tenant_id', 'metadata_field_id', 'canonical_value'],
                    'mva_canonical_idx'
                );
                // Duplicate clustering scan path.
                $table->index(
                    ['tenant_id', 'metadata_field_id', 'normalization_hash'],
                    'mva_normhash_idx'
                );

                $table->foreign('tenant_id')
                    ->references('id')->on('tenants')
                    ->cascadeOnDelete();
                $table->foreign('metadata_field_id')
                    ->references('id')->on('metadata_fields')
                    ->cascadeOnDelete();
                $table->foreign('created_by_user_id')
                    ->references('id')->on('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasTable('metadata_value_merges')) {
            Schema::create('metadata_value_merges', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('metadata_field_id');
                $table->string('from_value', 255);
                $table->string('to_value', 255);
                $table->unsignedInteger('rows_updated')->default(0);
                $table->unsignedInteger('options_removed')->default(0);
                $table->boolean('alias_recorded')->default(false);
                $table->string('source', 32)->default('manual');
                $table->unsignedBigInteger('performed_by_user_id')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('performed_at')->useCurrent();
                $table->timestamps();

                $table->index(
                    ['tenant_id', 'metadata_field_id'],
                    'mvm_field_idx'
                );
                $table->index('performed_at', 'mvm_performed_at_idx');

                $table->foreign('tenant_id')
                    ->references('id')->on('tenants')
                    ->cascadeOnDelete();
                $table->foreign('metadata_field_id')
                    ->references('id')->on('metadata_fields')
                    ->cascadeOnDelete();
                $table->foreign('performed_by_user_id')
                    ->references('id')->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('metadata_value_merges');
        Schema::dropIfExists('metadata_value_aliases');
    }
};
