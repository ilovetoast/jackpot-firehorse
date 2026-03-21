<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suggested new option values for metadata fields (not attached to assets).
     */
    public function up(): void
    {
        Schema::create('ai_metadata_value_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('field_key');
            $table->string('suggested_value', 512);
            $table->unsignedInteger('supporting_asset_count');
            $table->decimal('confidence', 6, 4);
            $table->string('source', 32); // tag_cluster | candidate_pattern
            $table->string('status', 32)->default('pending');
            $table->timestamps();

            $table->index(['tenant_id', 'field_key']);
            $table->index(['tenant_id', 'status']);
        });

        // Prefix columns: full utf8mb4 strings exceed MySQL's max index length for a composite unique.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement(
                'ALTER TABLE ai_metadata_value_suggestions ADD UNIQUE KEY amvs_tenant_field_value_source_unique (tenant_id, field_key(191), suggested_value(191), source)'
            );
        } else {
            Schema::table('ai_metadata_value_suggestions', function (Blueprint $table) {
                $table->unique(
                    ['tenant_id', 'field_key', 'suggested_value', 'source'],
                    'amvs_tenant_field_value_source_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_metadata_value_suggestions');
    }
};
