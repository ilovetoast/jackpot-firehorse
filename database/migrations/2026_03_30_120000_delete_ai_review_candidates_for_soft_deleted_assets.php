<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orphan cleanup: assets use soft deletes, so FK ON DELETE CASCADE never ran for
 * asset_tag_candidates / asset_metadata_candidates. Remove rows tied to trashed assets.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assets')) {
            return;
        }

        if (Schema::hasTable('asset_tag_candidates')) {
            DB::table('asset_tag_candidates')->whereIn('asset_id', function ($q) {
                $q->select('id')->from('assets')->whereNotNull('deleted_at');
            })->delete();
        }

        if (Schema::hasTable('asset_metadata_candidates')) {
            DB::table('asset_metadata_candidates')->whereIn('asset_id', function ($q) {
                $q->select('id')->from('assets')->whereNotNull('deleted_at');
            })->delete();
        }
    }

    public function down(): void
    {
        // Data migration — no safe rollback
    }
};
