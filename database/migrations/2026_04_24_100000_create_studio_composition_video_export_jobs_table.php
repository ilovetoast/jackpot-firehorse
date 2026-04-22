<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_composition_video_export_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('composition_id')->constrained('compositions')->cascadeOnDelete();
            $table->string('status', 32)->default('queued');
            $table->json('error_json')->nullable();
            $table->json('meta_json')->nullable();
            $table->uuid('output_asset_id')->nullable()->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->index(['composition_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_composition_video_export_jobs');
    }
};
