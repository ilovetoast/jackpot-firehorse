<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staged asset intake: Assets without category_id are marked intake_state='staged'
     * and shown on /assets/staged until classified with a category.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('intake_state', 16)->default('normal')->after('source');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->index('intake_state');
        });

        // Backfill: assets with category_id = normal, without = staged
        DB::table('assets')->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.category_id')), '') != ''")
            ->whereRaw("LOWER(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.category_id')), ''))) != 'null'")
            ->update(['intake_state' => 'normal']);

        DB::table('assets')->where(function ($q) {
            $q->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.category_id')), '') = ''")
                ->orWhereRaw("LOWER(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.category_id')), ''))) = 'null'");
        })->update(['intake_state' => 'staged']);
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['assets_intake_state_index']);
        });
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('intake_state');
        });
    }
};
