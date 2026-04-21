<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_animation_outputs', function (Blueprint $table) {
            $table->string('finalize_fingerprint', 64)->nullable()->after('studio_animation_job_id');
            $table->index(['studio_animation_job_id', 'finalize_fingerprint'], 'studio_anim_outputs_job_fingerprint_idx');
        });
    }

    public function down(): void
    {
        Schema::table('studio_animation_outputs', function (Blueprint $table) {
            $table->dropIndex('studio_anim_outputs_job_fingerprint_idx');
            $table->dropColumn('finalize_fingerprint');
        });
    }
};
