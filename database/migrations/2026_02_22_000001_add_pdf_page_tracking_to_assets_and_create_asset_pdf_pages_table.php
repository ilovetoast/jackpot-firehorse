<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->unsignedInteger('pdf_page_count')->nullable()->after('video_preview_url');
            $table->boolean('pdf_pages_rendered')->default(false)->after('pdf_page_count');
            $table->index('pdf_page_count');
            $table->index('pdf_pages_rendered');
        });

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

    public function down(): void
    {
        Schema::dropIfExists('asset_pdf_pages');

        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['pdf_page_count']);
            $table->dropIndex(['pdf_pages_rendered']);
            $table->dropColumn(['pdf_page_count', 'pdf_pages_rendered']);
        });
    }
};
