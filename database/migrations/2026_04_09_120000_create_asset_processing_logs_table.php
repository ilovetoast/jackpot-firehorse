<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_processing_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('asset_id');
            $table->string('action_type', 64);
            $table->timestamp('last_run_at');
            $table->timestamps();

            $table->unique(['asset_id', 'action_type']);
            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_processing_logs');
    }
};
