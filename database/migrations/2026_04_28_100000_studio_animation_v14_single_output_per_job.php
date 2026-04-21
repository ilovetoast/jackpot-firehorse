<?php

use App\Models\StudioAnimationOutput;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('studio_animation_outputs')) {
            return;
        }

        $dupJobIds = DB::table('studio_animation_outputs')
            ->select('studio_animation_job_id')
            ->groupBy('studio_animation_job_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('studio_animation_job_id');

        foreach ($dupJobIds as $jobId) {
            $keep = StudioAnimationOutput::query()
                ->where('studio_animation_job_id', (int) $jobId)
                ->orderByDesc('id')
                ->first();
            if ($keep === null) {
                continue;
            }
            StudioAnimationOutput::query()
                ->where('studio_animation_job_id', (int) $jobId)
                ->where('id', '!=', $keep->id)
                ->delete();
        }

        try {
            Schema::table('studio_animation_outputs', function (Blueprint $table) {
                $table->dropUnique('st_anim_out_job_fp_uniq');
            });
        } catch (\Throwable) {
            // May not exist on some installs.
        }

        Schema::table('studio_animation_outputs', function (Blueprint $table) {
            $table->unique('studio_animation_job_id', 'st_anim_out_job_id_uniq');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('studio_animation_outputs')) {
            return;
        }

        Schema::table('studio_animation_outputs', function (Blueprint $table) {
            $table->dropUnique('st_anim_out_job_id_uniq');
        });

        Schema::table('studio_animation_outputs', function (Blueprint $table) {
            $table->unique(['studio_animation_job_id', 'finalize_fingerprint'], 'st_anim_out_job_fp_uniq');
        });
    }
};
