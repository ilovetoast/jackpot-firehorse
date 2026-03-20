<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot: assets attached to an execution (composition).
     */
    public function up(): void
    {
        Schema::create('execution_assets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('execution_id')
                ->constrained('executions')
                ->cascadeOnDelete();

            $table->uuid('asset_id');

            $table->integer('sort_order')->default(0);

            $table->string('role')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->cascadeOnDelete();

            $table->index(['execution_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('execution_assets');
    }
};
