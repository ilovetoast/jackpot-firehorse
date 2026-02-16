<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make overall_score nullable for evaluation_status not_applicable/incomplete.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE brand_compliance_scores MODIFY overall_score TINYINT UNSIGNED NULL DEFAULT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE brand_compliance_scores ALTER COLUMN overall_score DROP NOT NULL, ALTER COLUMN overall_score DROP DEFAULT');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE brand_compliance_scores MODIFY overall_score TINYINT UNSIGNED NOT NULL DEFAULT 0');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE brand_compliance_scores ALTER COLUMN overall_score SET NOT NULL, ALTER COLUMN overall_score SET DEFAULT 0');
        }
    }
};
