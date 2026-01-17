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
        Schema::create('asset_tags', function (Blueprint $table) {
            $table->id();
            $table->uuid('asset_id');
            $table->string('tag');
            $table->string('source'); // user, ai
            $table->decimal('confidence', 5, 4)->nullable(); // 0.0000 to 1.0000
            $table->timestamp('created_at');

            // Foreign key constraint for asset_id
            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->onDelete('cascade');

            // Indexes
            $table->index('asset_id');
            $table->index('tag');
            $table->index(['asset_id', 'tag']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_tags');
    }
};
