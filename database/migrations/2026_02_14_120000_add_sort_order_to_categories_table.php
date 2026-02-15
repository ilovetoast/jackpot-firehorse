<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add sort_order to categories for ordering and visibility.
     * - Ensures is_hidden exists (boolean default false)
     * - Adds sort_order integer nullable
     * - Backfills existing categories by created_at ASC within brand_id + asset_type
     */
    public function up(): void
    {
        // Step 1: Ensure is_hidden exists
        if (! Schema::hasColumn('categories', 'is_hidden')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->boolean('is_hidden')->default(false)->after('is_locked');
                $table->index('is_hidden');
            });
        }

        // Step 2: Add sort_order
        if (! Schema::hasColumn('categories', 'sort_order')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->integer('sort_order')->nullable()->after('order');
            });
        }

        // Step 3: Backfill existing categories by created_at ASC within brand_id + asset_type
        $rows = DB::table('categories')
            ->whereNull('deleted_at')
            ->orderBy('brand_id')
            ->orderBy('asset_type')
            ->orderBy('created_at')
            ->get(['id', 'brand_id', 'asset_type']);

        $groupKey = null;
        $ordinal = 0;
        foreach ($rows as $row) {
            $key = (string) ($row->brand_id ?? '') . '|' . (string) ($row->asset_type ?? '');
            if ($key !== $groupKey) {
                $groupKey = $key;
                $ordinal = 0;
            }
            $ordinal++;
            DB::table('categories')->where('id', $row->id)->update(['sort_order' => $ordinal]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('categories', 'sort_order')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('sort_order');
            });
        }
        // Do NOT drop is_hidden - it may have been added by a prior migration
    }
};
