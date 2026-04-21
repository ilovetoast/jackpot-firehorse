<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            // TEXT/BLOB cannot use full-column indexes in MySQL; drop first, widen, then prefix indexes.
            Schema::table('studio_animation_jobs', function (Blueprint $table) {
                $table->dropIndex(['provider_job_id']);
                $table->dropIndex(['provider', 'provider_job_id']);
            });
            DB::statement('ALTER TABLE studio_animation_jobs MODIFY provider_job_id TEXT NULL');
            DB::statement('ALTER TABLE studio_animation_jobs ADD INDEX studio_animation_jobs_provider_job_id_index (provider_job_id(191))');
            DB::statement('ALTER TABLE studio_animation_jobs ADD INDEX studio_animation_jobs_provider_provider_job_id_index (provider, provider_job_id(191))');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE studio_animation_jobs ALTER COLUMN provider_job_id TYPE TEXT USING provider_job_id::text');
        }
        // SQLite: VARCHAR(128) is sufficient for tests; FAL JSON payloads stay short in mock mode.
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE studio_animation_jobs DROP INDEX studio_animation_jobs_provider_job_id_index');
            DB::statement('ALTER TABLE studio_animation_jobs DROP INDEX studio_animation_jobs_provider_provider_job_id_index');
            DB::statement('ALTER TABLE studio_animation_jobs MODIFY provider_job_id VARCHAR(128) NULL');
            Schema::table('studio_animation_jobs', function (Blueprint $table) {
                $table->index('provider_job_id');
                $table->index(['provider', 'provider_job_id']);
            });
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE studio_animation_jobs ALTER COLUMN provider_job_id TYPE VARCHAR(128)');
        }
    }
};
