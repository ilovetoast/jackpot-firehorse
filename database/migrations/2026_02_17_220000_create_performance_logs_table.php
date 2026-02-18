<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_logs', function (Blueprint $table) {
            $table->id();
            $table->string('url', 2048);
            $table->string('method', 16);
            $table->unsignedInteger('duration_ms');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('memory_usage')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index('duration_ms');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_logs');
    }
};
