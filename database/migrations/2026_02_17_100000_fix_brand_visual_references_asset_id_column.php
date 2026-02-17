<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Fix asset_id column type: must be CHAR(36) for UUID to match assets.id.
     * Handles tables created with wrong type (e.g. unsignedBigInteger from foreignId).
     */
    public function up(): void
    {
        if (! Schema::hasTable('brand_visual_references')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql') {
            return;
        }

        $dbName = Schema::getConnection()->getDatabaseName();
        $columnType = DB::selectOne(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'brand_visual_references' AND COLUMN_NAME = 'asset_id'",
            [$dbName]
        );
        $dataType = $columnType->DATA_TYPE ?? null;
        if (! in_array($dataType, ['bigint', 'int', 'integer'], true)) {
            return;
        }

        Schema::table('brand_visual_references', function (Blueprint $table) {
            $table->dropForeign(['asset_id']);
        });
        DB::statement('ALTER TABLE brand_visual_references MODIFY asset_id CHAR(36) NULL');
        Schema::table('brand_visual_references', function (Blueprint $table) {
            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Irreversible without knowing original type
    }
};
