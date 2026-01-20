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
        Schema::create('asset_metadata_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_metadata_id')->constrained('asset_metadata')->onDelete('cascade');
            $table->json('old_value_json')->nullable();
            $table->json('new_value_json')->nullable();
            $table->string('source'); // user, ai, system
            $table->foreignId('changed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('created_at');

            // Indexes
            $table->index('asset_metadata_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_metadata_history');
    }
};
