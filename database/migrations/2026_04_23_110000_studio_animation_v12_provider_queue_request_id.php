<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_animation_jobs', function (Blueprint $table) {
            $table->string('provider_queue_request_id', 128)->nullable()->after('provider_job_id');
            $table->index(['provider', 'provider_queue_request_id'], 'studio_anim_jobs_provider_ext_req_idx');
        });
    }

    public function down(): void
    {
        Schema::table('studio_animation_jobs', function (Blueprint $table) {
            $table->dropIndex('studio_anim_jobs_provider_ext_req_idx');
            $table->dropColumn('provider_queue_request_id');
        });
    }
};
