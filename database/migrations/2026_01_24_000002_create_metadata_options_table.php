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
        Schema::create('metadata_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('metadata_field_id')->constrained('metadata_fields')->onDelete('restrict');
            $table->string('value');
            $table->string('system_label');
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            // Indexes
            $table->index('metadata_field_id');
            $table->index(['metadata_field_id', 'value']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metadata_options');
    }
};
