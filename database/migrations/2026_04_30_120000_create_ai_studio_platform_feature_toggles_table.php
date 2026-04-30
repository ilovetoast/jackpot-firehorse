<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotent: a failed first run can leave the table without the unique index
     * (MySQL long default index name), while the migration row is not recorded — then
     * `create table` would hit "already exists".
     */
    public function up(): void
    {
        if (! Schema::hasTable('ai_studio_platform_feature_toggles')) {
            Schema::create('ai_studio_platform_feature_toggles', function (Blueprint $table) {
                $table->id();
                $table->string('feature_key', 96)->index();
                /** Empty string = all environments; otherwise matches {@see app()->environment()}. */
                $table->string('environment', 64)->default('');
                $table->boolean('enabled');
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                // MySQL max identifier length 64; Laravel’s default name exceeds it for this table name.
                $table->unique(['feature_key', 'environment'], 'ai_studio_pf_tog_feat_env_uniq');
            });

            return;
        }

        if (! $this->hasIndex('ai_studio_platform_feature_toggles', 'ai_studio_pf_tog_feat_env_uniq')) {
            Schema::table('ai_studio_platform_feature_toggles', function (Blueprint $table) {
                $table->unique(['feature_key', 'environment'], 'ai_studio_pf_tog_feat_env_uniq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_studio_platform_feature_toggles');
    }

    protected function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $databaseName = $connection->getDatabaseName();

        $result = DB::select(
            'SELECT COUNT(*) as count FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$databaseName, $table, $indexName]
        );

        return (int) $result[0]->count > 0;
    }
};
