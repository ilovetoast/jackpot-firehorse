<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Execution-Based Brand Intelligence scores (execution and/or asset targets).
     */
    public function up(): void
    {
        Schema::create('brand_intelligence_scores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();

            $table->foreignId('execution_id')
                ->nullable()
                ->constrained('executions')
                ->cascadeOnDelete();

            $table->uuid('asset_id')->nullable();

            $table->integer('overall_score')->nullable();

            $table->float('confidence')->nullable();

            $table->string('level')->nullable();

            $table->json('breakdown_json')->nullable();

            $table->string('engine_version')->nullable();

            $table->boolean('ai_used')->default(false);

            $table->timestamps();

            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->cascadeOnDelete();
            // brand_id, execution_id, asset_id indexed via foreign keys (InnoDB).
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_intelligence_scores');
    }
};
