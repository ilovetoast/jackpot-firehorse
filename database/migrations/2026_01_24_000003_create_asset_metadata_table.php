<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('asset_metadata', function (Blueprint $table) {
            $table->id();
            $table->uuid('asset_id');
            $table->foreignId('metadata_field_id')->constrained('metadata_fields')->onDelete('restrict');
            $table->json('value_json');
            $table->string('source'); // user, ai, import
            $table->decimal('confidence', 5, 4)->nullable(); // 0.0000 to 1.0000
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            // Foreign key constraint for asset_id
            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->onDelete('cascade');

            // Indexes
            $table->index('asset_id');
            $table->index('metadata_field_id');
            $table->index(['asset_id', 'metadata_field_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_metadata');
    }
};
