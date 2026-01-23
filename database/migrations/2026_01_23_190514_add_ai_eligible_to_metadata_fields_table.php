<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds ai_eligible flag to metadata_fields table.
     * When true, AI can generate suggestions for this field.
     * AI suggestions are only allowed if:
     * - ai_eligible = true
     * - Field has allowed_values (metadata_options) defined
     * - Field type is select or multiselect
     */
    public function up(): void
    {
        Schema::table('metadata_fields', function (Blueprint $table) {
            if (!Schema::hasColumn('metadata_fields', 'ai_eligible')) {
                $table->boolean('ai_eligible')->default(false)->after('is_ai_trainable');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('metadata_fields', function (Blueprint $table) {
            if (Schema::hasColumn('metadata_fields', 'ai_eligible')) {
                $table->dropColumn('ai_eligible');
            }
        });
    }
};
