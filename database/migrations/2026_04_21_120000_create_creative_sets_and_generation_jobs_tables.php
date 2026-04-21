<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 255);
            /** @see \App\Models\CreativeSet::STATUS_* */
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'brand_id']);
        });

        Schema::create('creative_set_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creative_set_id')->constrained('creative_sets')->cascadeOnDelete();
            $table->foreignId('composition_id')->constrained('compositions')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('label', 255)->nullable();
            /** @see \App\Models\CreativeSetVariant::STATUS_* */
            $table->string('status', 32)->default('ready');
            $table->json('axis')->nullable();
            /** Logical link to generation_job_items.id (Phase 2); no DB FK — avoids create-order cycle with job_items. */
            $table->unsignedBigInteger('generation_job_item_id')->nullable()->index();
            $table->timestamps();

            $table->unique('composition_id');
            $table->index(['creative_set_id', 'sort_order']);
        });

        Schema::create('generation_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creative_set_id')->constrained('creative_sets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            /** @see \App\Models\GenerationJob::STATUS_* */
            $table->string('status', 32)->default('queued');
            $table->json('axis_snapshot')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['creative_set_id', 'status']);
        });

        Schema::create('generation_job_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_job_id')->constrained('generation_jobs')->cascadeOnDelete();
            $table->string('combination_key', 255);
            /** @see \App\Models\GenerationJobItem::STATUS_* */
            $table->string('status', 32)->default('pending');
            $table->foreignId('creative_set_variant_id')->nullable()->constrained('creative_set_variants')->nullOnDelete();
            $table->foreignId('composition_id')->nullable()->constrained('compositions')->nullOnDelete();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('error')->nullable();
            $table->timestamps();

            $table->index(['generation_job_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_job_items');
        Schema::dropIfExists('generation_jobs');
        Schema::dropIfExists('creative_set_variants');
        Schema::dropIfExists('creative_sets');
    }
};
