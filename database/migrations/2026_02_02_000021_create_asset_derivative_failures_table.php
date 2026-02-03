<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase T-1: Asset derivative failure observability.
 *
 * Tracks failures in thumbnails, previews, posters, waveforms.
 * Does NOT modify Asset.status or visibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_derivative_failures', function (Blueprint $table) {
            $table->id();
            $table->uuid('asset_id')->index();
            $table->string('derivative_type', 50)->index(); // thumbnail, preview, poster, waveform
            $table->string('processor', 50)->index(); // ffmpeg, imagemagick, sharp
            $table->string('failure_reason', 100)->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('last_failed_at')->nullable();
            $table->unsignedBigInteger('escalation_ticket_id')->nullable();
            $table->json('metadata')->nullable(); // codec, mime, exception trace, etc.
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
            $table->unique(['asset_id', 'derivative_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_derivative_failures');
    }
};
