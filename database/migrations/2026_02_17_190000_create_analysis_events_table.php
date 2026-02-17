<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable log of analysis_status transitions for debugging and audit.
     */
    public function up(): void
    {
        Schema::create('analysis_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('asset_id');
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->string('job', 128)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->index(['asset_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_events');
    }
};
