<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::table('studio_animation_outputs', function (Blueprint $table) {
                $table->dropIndex('studio_anim_outputs_job_fingerprint_idx');
            });
        } catch (\Throwable) {
            // Index may be missing on some installs.
        }

        Schema::table('studio_animation_outputs', function (Blueprint $table) {
            // MySQL/SQLite/Postgres: multiple rows with NULL finalize_fingerprint remain allowed for legacy rows.
            $table->unique(['studio_animation_job_id', 'finalize_fingerprint'], 'st_anim_out_job_fp_uniq');
        });
    }

    public function down(): void
    {
        Schema::table('studio_animation_outputs', function (Blueprint $table) {
            $table->dropUnique('st_anim_out_job_fp_uniq');
        });

        Schema::table('studio_animation_outputs', function (Blueprint $table) {
            $table->index(['studio_animation_job_id', 'finalize_fingerprint'], 'studio_anim_outputs_job_fingerprint_idx');
        });
    }
};
