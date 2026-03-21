<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfill categories.settings.ebi_enabled when missing; create feedback table.
     */
    public function up(): void
    {
        Category::query()->orderBy('id')->chunkById(100, function ($categories) {
            foreach ($categories as $category) {
                $settings = $category->settings ?? [];
                if (! array_key_exists('ebi_enabled', $settings)) {
                    $settings['ebi_enabled'] = Category::defaultEbiEnabledForSystemSlug((string) $category->slug);
                    $category->update(['settings' => $settings]);
                }
            }
        });

        if (! Schema::hasTable('brand_intelligence_feedback')) {
            Schema::create('brand_intelligence_feedback', function (Blueprint $table) {
                $table->id();
                $table->uuid('asset_id')->nullable();
                $table->foreign('asset_id')->references('id')->on('assets')->nullOnDelete();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
                $table->string('type');
                $table->string('rating');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['tenant_id', 'created_at']);
                $table->index('asset_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_intelligence_feedback');
    }
};
