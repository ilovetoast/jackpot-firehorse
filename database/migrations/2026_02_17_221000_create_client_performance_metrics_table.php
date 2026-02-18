<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_performance_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('url', 2048);
            $table->string('path', 512)->nullable();
            $table->unsignedInteger('ttfb_ms')->nullable();
            $table->unsignedInteger('dom_content_loaded_ms')->nullable();
            $table->unsignedInteger('load_event_ms')->nullable();
            $table->unsignedInteger('total_load_ms')->nullable();
            $table->unsignedInteger('avg_image_load_ms')->nullable();
            $table->unsignedInteger('image_count')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('session_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index('path');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_performance_metrics');
    }
};
