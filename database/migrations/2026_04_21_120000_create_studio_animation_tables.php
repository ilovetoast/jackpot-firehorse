<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recover from a failed partial run (e.g. long index name on `renders`): `jobs` existed but later tables were never created.
        if (! Schema::hasTable('studio_animation_outputs') && Schema::hasTable('studio_animation_jobs')) {
            Schema::dropIfExists('studio_animation_outputs');
            Schema::dropIfExists('studio_animation_renders');
            Schema::drop('studio_animation_jobs');
        }

        Schema::create('studio_animation_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('studio_document_id')->nullable()->index();
            $table->foreignId('composition_id')->nullable()->constrained('compositions')->nullOnDelete();
            $table->string('provider', 64);
            $table->string('provider_model', 128);
            $table->string('status', 32)->index();
            $table->string('source_strategy', 48);
            $table->text('prompt')->nullable();
            $table->text('negative_prompt')->nullable();
            $table->string('motion_preset', 128)->nullable();
            $table->unsignedSmallInteger('duration_seconds');
            $table->string('aspect_ratio', 16);
            $table->boolean('generate_audio')->default(false);
            $table->json('settings_json')->nullable();
            $table->json('provider_request_json')->nullable();
            $table->json('provider_response_json')->nullable();
            $table->string('provider_job_id', 128)->nullable()->index();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'brand_id']);
            $table->index(['tenant_id', 'brand_id', 'status']);
            $table->index(['composition_id', 'status']);
            $table->index(['provider', 'provider_job_id']);
        });

        Schema::create('studio_animation_renders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_animation_job_id')->constrained('studio_animation_jobs')->cascadeOnDelete();
            $table->string('render_role', 32);
            $table->foreignUuid('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('disk', 32);
            $table->string('path', 2048);
            $table->string('mime_type', 128);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('sha256', 64)->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            // MySQL max identifier length 64; Laravel default name exceeds that here.
            $table->index(['studio_animation_job_id', 'render_role'], 'st_anim_renders_job_role_idx');
        });

        Schema::create('studio_animation_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_animation_job_id')->constrained('studio_animation_jobs')->cascadeOnDelete();
            $table->foreignUuid('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('disk', 32);
            $table->string('video_path', 2048);
            $table->string('poster_path', 2048)->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index('studio_animation_job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_animation_outputs');
        Schema::dropIfExists('studio_animation_renders');
        Schema::dropIfExists('studio_animation_jobs');
    }
};
