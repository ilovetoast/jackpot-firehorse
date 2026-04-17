<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_onboarding_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('current_step')->default('welcome');

            // Activation — Brand Shell
            $table->boolean('brand_name_confirmed')->default(false);
            $table->boolean('primary_color_set')->default(false);

            // Brand mark: confirmed only when real proof exists
            $table->boolean('brand_mark_confirmed')->default(false);
            $table->string('brand_mark_type')->nullable(); // 'logo' | 'monogram' | null
            $table->string('brand_mark_asset_id')->nullable(); // linked logo asset UUID when type=logo

            // Activation — Starter Assets
            $table->unsignedInteger('starter_assets_count')->default(0);

            // Enrichment (optional, recommended)
            $table->boolean('guideline_uploaded')->default(false);
            $table->string('website_url')->nullable();
            $table->string('industry')->nullable();
            $table->string('enrichment_processing_status')->nullable(); // queued | processing | complete | failed
            $table->text('enrichment_processing_detail')->nullable();

            // Category preferences + flexible storage
            $table->boolean('category_preferences_saved')->default(false);
            $table->json('metadata')->nullable();

            // Lifecycle
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('dismissed_at')->nullable(); // cinematic flow dismissed, card still visible
            $table->timestamp('card_dismissed_at')->nullable(); // overview card permanently hidden
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_onboarding_progress');
    }
};
