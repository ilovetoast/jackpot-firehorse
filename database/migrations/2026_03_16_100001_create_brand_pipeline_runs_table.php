<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_pipeline_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_model_version_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('asset_id')->nullable();
            $table->string('stage', 64)->default('init'); // init, rendering, analyzing, merging, completed, failed
            $table->unsignedSmallInteger('pages_total')->default(0);
            $table->unsignedSmallInteger('pages_processed')->default(0);
            $table->string('extraction_mode', 32)->default('text'); // text | vision
            $table->string('status', 32)->default('pending'); // pending, processing, completed, failed
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'brand_model_version_id', 'asset_id'], 'bpr_brand_draft_asset_idx');
            $table->index('status');
            if (Schema::hasTable('assets')) {
                $table->foreign('asset_id')->references('id')->on('assets')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_pipeline_runs');
    }
};
