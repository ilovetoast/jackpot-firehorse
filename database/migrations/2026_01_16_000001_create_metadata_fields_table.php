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
        Schema::create('metadata_fields', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('system_label');
            $table->string('type'); // select, multiselect, text, number, boolean, date, rating, computed
            $table->string('applies_to'); // image, video, document, all
            $table->string('scope'); // system, tenant
            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_user_editable')->default(true);
            $table->boolean('is_ai_trainable')->default(false);
            $table->boolean('is_upload_visible')->default(true);
            $table->boolean('is_internal_only')->default(false);
            $table->string('group_key')->nullable(); // UI grouping only
            $table->string('plan_gate')->nullable();
            $table->timestamp('deprecated_at')->nullable();
            $table->foreignId('replacement_field_id')->nullable()->constrained('metadata_fields')->onDelete('set null');
            $table->timestamps();

            // Indexes
            $table->index('key');
            $table->index('scope');
            $table->index('deprecated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metadata_fields');
    }
};
