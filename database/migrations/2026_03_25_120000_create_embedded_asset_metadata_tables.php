<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layer B: raw embedded metadata payloads (per asset, per source).
 * Layer C: allowlisted derived index for search / future filters.
 *
 * TODO (future): If asset_versions becomes the primary unit for metadata, add optional
 * asset_version_id to these tables and backfill; current implementation is asset-level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->timestamp('captured_at')->nullable()->after('expires_at');
        });

        Schema::create('asset_metadata_payloads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id');
            $table->string('source', 64)->default('embedded');
            $table->string('schema_version', 32)->default('1');
            $table->json('payload_json');
            $table->timestamp('extracted_at')->nullable();
            $table->timestamps();

            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->cascadeOnDelete();

            $table->unique(['asset_id', 'source']);
            $table->index(['asset_id', 'source']);
        });

        Schema::create('asset_metadata_index', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id');
            $table->string('namespace', 64);
            $table->string('key', 255);
            $table->string('normalized_key', 128);
            $table->string('value_type', 32);
            $table->string('value_string', 4096)->nullable();
            $table->decimal('value_number', 24, 8)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->date('value_date')->nullable();
            $table->timestamp('value_datetime')->nullable();
            $table->json('value_json')->nullable();
            $table->text('search_text')->nullable();
            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_visible')->default(false);
            $table->unsignedInteger('source_priority')->default(100);
            $table->timestamps();

            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->cascadeOnDelete();

            $table->index('asset_id');
            $table->index(['namespace', 'normalized_key'], 'asset_metadata_index_ns_norm_key');
            $table->index(['normalized_key', 'value_number'], 'asset_metadata_index_norm_num');
            $table->index(['normalized_key', 'value_date'], 'asset_metadata_index_norm_date');
            $table->index(['normalized_key', 'value_datetime'], 'asset_metadata_index_norm_dt');
            $table->index('is_filterable');
            $table->index('normalized_key', 'asset_metadata_index_normalized_key_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                'CREATE INDEX asset_metadata_index_norm_str_prefix ON asset_metadata_index (normalized_key, value_string(191))'
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('DROP INDEX asset_metadata_index_norm_str_prefix ON asset_metadata_index');
        }

        Schema::dropIfExists('asset_metadata_index');
        Schema::dropIfExists('asset_metadata_payloads');

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('captured_at');
        });
    }
};
