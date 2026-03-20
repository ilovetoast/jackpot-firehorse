<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Executions (Deliverables) — composed brand outputs; primary EBI scoring target.
     */
    public function up(): void
    {
        Schema::create('executions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();

            $table->foreignId('category_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('name');

            $table->enum('status', ['draft', 'finalized', 'archived'])->default('draft');

            $table->json('context_json')->nullable();

            $table->uuid('primary_asset_id')->nullable()->index();

            $table->timestamp('finalized_at')->nullable();

            $table->timestamps();

            $table->foreign('primary_asset_id')
                ->references('id')
                ->on('assets')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('executions');
    }
};
