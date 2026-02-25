<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            if (!Schema::hasColumn('assets', 'pdf_page_count')) {
                $table->unsignedInteger('pdf_page_count')->nullable()->after('video_preview_url');
            }
            if (!Schema::hasColumn('assets', 'pdf_pages_rendered')) {
                $table->boolean('pdf_pages_rendered')->default(false)->after('pdf_page_count');
            }
        });

        $this->addIndexIfMissing('assets', 'pdf_page_count', 'assets_pdf_page_count_index');
        $this->addIndexIfMissing('assets', 'pdf_pages_rendered', 'assets_pdf_pages_rendered_index');

        if (!Schema::hasTable('asset_pdf_pages')) {
        Schema::create('asset_pdf_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('asset_id');
            $table->uuid('asset_version_id')->nullable();
            $table->unsignedInteger('version_number')->default(1);
            $table->unsignedInteger('page_number');
            $table->string('storage_path')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('mime_type')->default('image/webp');
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('rendered_at')->nullable();
            $table->timestamps();

            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->onDelete('cascade');
            $table->foreign('asset_version_id')
                ->references('id')
                ->on('asset_versions')
                ->nullOnDelete();

            $table->unique(
                ['asset_id', 'version_number', 'page_number'],
                'asset_pdf_pages_asset_version_page_unique'
            );
            $table->index(['tenant_id', 'asset_id']);
            $table->index(['asset_id', 'status']);
        });
        }
    }

    /**
     * Add index on column if the index does not exist (MySQL).
     */
    private function addIndexIfMissing(string $table, string $column, string $indexName): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql') {
            Schema::table($table, fn (Blueprint $t) => $t->index([$column]));

            return;
        }
        $exists = DB::selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [Schema::getConnection()->getDatabaseName(), $table, $indexName]
        );
        if (!$exists) {
            Schema::table($table, fn (Blueprint $t) => $t->index([$column], $indexName));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_pdf_pages');

        if (Schema::hasColumn('assets', 'pdf_page_count') || Schema::hasColumn('assets', 'pdf_pages_rendered')) {
            Schema::table('assets', function (Blueprint $table) {
                if (Schema::hasColumn('assets', 'pdf_page_count')) {
                    $table->dropIndex(['pdf_page_count']);
                }
                if (Schema::hasColumn('assets', 'pdf_pages_rendered')) {
                    $table->dropIndex(['pdf_pages_rendered']);
                }
                $table->dropColumn(array_filter(
                    ['pdf_page_count', 'pdf_pages_rendered'],
                    fn (string $col) => Schema::hasColumn('assets', $col)
                ));
            });
        }
    }
};
