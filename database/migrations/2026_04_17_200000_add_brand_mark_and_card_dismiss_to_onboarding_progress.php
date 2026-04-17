<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconcile the brand_onboarding_progress table with the current schema.
 *
 * Handles renames from the original migration (logo_uploaded → brand_mark_confirmed,
 * completed_by_user_id → activated_by_user_id) and adds all columns that were
 * introduced after the initial create migration shipped.
 */
return new class extends Migration
{
    private const TABLE = 'brand_onboarding_progress';

    public function up(): void
    {
        // ── Renames ─────────────────────────────────────────────────
        if (Schema::hasColumn(self::TABLE, 'logo_uploaded') && ! Schema::hasColumn(self::TABLE, 'brand_mark_confirmed')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->renameColumn('logo_uploaded', 'brand_mark_confirmed');
            });
        }

        if (Schema::hasColumn(self::TABLE, 'completed_by_user_id') && ! Schema::hasColumn(self::TABLE, 'activated_by_user_id')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->renameColumn('completed_by_user_id', 'activated_by_user_id');
            });
        }

        // ── New columns (idempotent) ────────────────────────────────
        Schema::table(self::TABLE, function (Blueprint $table) {
            if (! Schema::hasColumn(self::TABLE, 'brand_mark_confirmed')) {
                $table->boolean('brand_mark_confirmed')->default(false);
            }
            if (! Schema::hasColumn(self::TABLE, 'brand_mark_type')) {
                $table->string('brand_mark_type')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'brand_mark_asset_id')) {
                $table->string('brand_mark_asset_id')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'enrichment_processing_status')) {
                $table->string('enrichment_processing_status')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'enrichment_processing_detail')) {
                $table->text('enrichment_processing_detail')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'activated_at')) {
                $table->timestamp('activated_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'dismissed_at')) {
                $table->timestamp('dismissed_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'card_dismissed_at')) {
                $table->timestamp('card_dismissed_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'activated_by_user_id')) {
                $table->unsignedBigInteger('activated_by_user_id')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'category_preferences_saved')) {
                $table->boolean('category_preferences_saved')->default(false);
            }
            if (! Schema::hasColumn(self::TABLE, 'metadata')) {
                $table->json('metadata')->nullable();
            }
        });
    }

    public function down(): void
    {
        $addedCols = [
            'brand_mark_type', 'brand_mark_asset_id',
            'enrichment_processing_status', 'enrichment_processing_detail',
            'activated_at', 'dismissed_at', 'card_dismissed_at',
            'category_preferences_saved', 'metadata',
        ];

        $toDrop = [];
        foreach ($addedCols as $col) {
            if (Schema::hasColumn(self::TABLE, $col)) {
                $toDrop[] = $col;
            }
        }
        if ($toDrop) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($toDrop) {
                $table->dropColumn($toDrop);
            });
        }

        if (Schema::hasColumn(self::TABLE, 'brand_mark_confirmed') && ! Schema::hasColumn(self::TABLE, 'logo_uploaded')) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->renameColumn('brand_mark_confirmed', 'logo_uploaded'));
        }
        if (Schema::hasColumn(self::TABLE, 'activated_by_user_id') && ! Schema::hasColumn(self::TABLE, 'completed_by_user_id')) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->renameColumn('activated_by_user_id', 'completed_by_user_id'));
        }
    }
};
