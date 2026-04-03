<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_category_field_defaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_category_id')->constrained('system_categories')->cascadeOnDelete();
            $table->foreignId('metadata_field_id')->constrained('metadata_fields')->cascadeOnDelete();
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_upload_hidden')->default(false);
            $table->boolean('is_filter_hidden')->default(false);
            $table->boolean('is_edit_hidden')->default(false);
            $table->boolean('is_primary')->nullable();
            $table->timestamps();

            $table->unique(['system_category_id', 'metadata_field_id'], 'scfd_system_cat_field_unique');
            $table->index('metadata_field_id', 'scfd_metadata_field_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_category_field_defaults');
    }
};
