<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
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
        $this->addCapturedAtColumnIfMissing();

        if (! Schema::hasTable('asset_metadata_payloads')) {
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
        }

        if (! Schema::hasTable('asset_metadata_index')) {
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
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $this->createMysqlPrefixIndexIfMissing();
        }
    }

    /**
     * Hot tables + concurrent traffic can deadlock metadata locks on ALTER. Retry + online DDL.
     */
    private function addCapturedAtColumnIfMissing(): void
    {
        if (Schema::hasColumn('assets', 'captured_at')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $this->retryOnDeadlock(function () {
                if (Schema::hasColumn('assets', 'captured_at')) {
                    return;
                }
                // Prefer INSTANT (8.0.12+): minimal locking. INPLACE+LOCK=NONE if not supported.
                try {
                    DB::statement(
                        'ALTER TABLE `assets` ADD COLUMN `captured_at` TIMESTAMP NULL DEFAULT NULL AFTER `expires_at`, ALGORITHM=INSTANT, LOCK=NONE'
                    );
                } catch (QueryException $e) {
                    if ($this->isDeadlockOrSerializationFailure($e)) {
                        throw $e;
                    }
                    if (Schema::hasColumn('assets', 'captured_at')) {
                        return;
                    }
                    DB::statement(
                        'ALTER TABLE `assets` ADD COLUMN `captured_at` TIMESTAMP NULL DEFAULT NULL AFTER `expires_at`, ALGORITHM=INPLACE, LOCK=NONE'
                    );
                }
            });

            return;
        }

        $this->retryOnDeadlock(function () {
            Schema::table('assets', function (Blueprint $table) {
                $table->timestamp('captured_at')->nullable()->after('expires_at');
            });
        });
    }

    /**
     * @param  callable():void  $callback
     */
    private function retryOnDeadlock(callable $callback, int $maxAttempts = 8): void
    {
        $attempt = 0;
        while ($attempt < $maxAttempts) {
            try {
                $callback();

                return;
            } catch (QueryException $e) {
                if (! $this->isDeadlockOrSerializationFailure($e) || $attempt >= $maxAttempts - 1) {
                    throw $e;
                }
                $attempt++;
                usleep(random_int(150_000, 600_000));
            }
        }
    }

    private function isDeadlockOrSerializationFailure(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        if ($code === '40001') {
            return true;
        }
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : null;
        if ($driverCode === 1213 || $driverCode === 1205) {
            return true;
        }

        $msg = $e->getMessage();

        return str_contains($msg, '1213') || str_contains($msg, 'Deadlock')
            || str_contains($msg, '1205') || str_contains($msg, 'Lock wait timeout');
    }

    private function createMysqlPrefixIndexIfMissing(): void
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND index_name = ?
             LIMIT 1',
            ['asset_metadata_index', 'asset_metadata_index_norm_str_prefix']
        );
        if ($exists) {
            return;
        }

        $this->retryOnDeadlock(function () {
            DB::statement(
                'CREATE INDEX asset_metadata_index_norm_str_prefix ON asset_metadata_index (normalized_key, value_string(191))'
            );
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql' && Schema::hasTable('asset_metadata_index')) {
            $idx = DB::selectOne(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name = ?
                   AND index_name = ?
                 LIMIT 1',
                ['asset_metadata_index', 'asset_metadata_index_norm_str_prefix']
            );
            if ($idx) {
                $this->retryOnDeadlock(function () {
                    DB::statement('DROP INDEX asset_metadata_index_norm_str_prefix ON asset_metadata_index');
                });
            }
        }

        Schema::dropIfExists('asset_metadata_index');
        Schema::dropIfExists('asset_metadata_payloads');

        if (Schema::hasColumn('assets', 'captured_at')) {
            $this->retryOnDeadlock(function () {
                Schema::table('assets', function (Blueprint $table) {
                    $table->dropColumn('captured_at');
                });
            });
        }
    }
};
