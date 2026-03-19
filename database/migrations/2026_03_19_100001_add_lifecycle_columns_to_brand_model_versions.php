<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_model_versions', function (Blueprint $table) {
            $table->string('lifecycle_stage', 32)->default('research')->after('builder_progress');
            $table->string('research_status', 32)->default('not_started')->after('lifecycle_stage');
            $table->string('review_status', 32)->default('pending')->after('research_status');
            $table->timestamp('research_started_at')->nullable()->after('review_status');
            $table->timestamp('research_completed_at')->nullable()->after('research_started_at');
            $table->timestamp('review_completed_at')->nullable()->after('research_completed_at');
        });

        // Backfill existing rows based on current state
        // Active/archived versions → published
        DB::table('brand_model_versions')
            ->whereIn('status', ['active', 'archived'])
            ->update([
                'lifecycle_stage' => 'published',
                'research_status' => 'complete',
                'review_status' => 'complete',
            ]);

        // Drafts that have visited a build step → build
        $buildSteps = ['archetype', 'purpose_promise', 'expression', 'positioning', 'standards', 'review'];
        DB::table('brand_model_versions')
            ->where('status', 'draft')
            ->where(function ($q) use ($buildSteps) {
                foreach ($buildSteps as $step) {
                    $q->orWhereJsonContains('builder_progress->last_visited_step', $step);
                }
            })
            ->update([
                'lifecycle_stage' => 'build',
                'research_status' => 'complete',
                'review_status' => 'complete',
            ]);

        // Drafts with completed snapshots but not yet in build → review
        $draftIds = DB::table('brand_model_versions')
            ->where('status', 'draft')
            ->where('lifecycle_stage', 'research')
            ->pluck('id');

        if ($draftIds->isNotEmpty()) {
            $withSnapshots = DB::table('brand_pipeline_snapshots')
                ->whereIn('brand_model_version_id', $draftIds)
                ->where('status', 'completed')
                ->pluck('brand_model_version_id')
                ->unique();

            if ($withSnapshots->isNotEmpty()) {
                DB::table('brand_model_versions')
                    ->whereIn('id', $withSnapshots)
                    ->where('lifecycle_stage', 'research')
                    ->update([
                        'lifecycle_stage' => 'review',
                        'research_status' => 'complete',
                    ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('brand_model_versions', function (Blueprint $table) {
            $table->dropColumn([
                'lifecycle_stage',
                'research_status',
                'review_status',
                'research_started_at',
                'research_completed_at',
                'review_completed_at',
            ]);
        });
    }
};
