<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs partial state when 2026_04_23_120000 failed mid-way (MySQL 64-char index name limit on some installs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('studio_variant_groups') && ! Schema::hasTable('studio_variant_group_members')) {
            Schema::create('studio_variant_group_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('studio_variant_group_id')->constrained('studio_variant_groups')->cascadeOnDelete();
                $table->foreignId('composition_id')->nullable()->constrained('compositions')->cascadeOnDelete();
                $table->string('slot_key', 64)->nullable();
                $table->string('label', 255)->nullable();
                $table->string('status', 32)->default('draft');
                $table->string('generation_status', 32)->nullable();
                $table->json('spec_json')->nullable();
                $table->unsignedBigInteger('generation_job_item_id')->nullable()->index();
                $table->uuid('result_asset_id')->nullable()->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['studio_variant_group_id', 'sort_order'], 'svg_members_group_sort_idx');
                $table->index('composition_id');
            });
        }
    }

    public function down(): void
    {
        //
    }
};
