<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: a failed partial run can leave `studio_variant_groups` without the migration
        // batch row, causing "table already exists" on the next `migrate`.
        if (! Schema::hasTable('studio_variant_groups')) {
            Schema::create('studio_variant_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_composition_id')->constrained('compositions')->cascadeOnDelete();
            $table->foreignId('source_composition_version_id')->nullable()->constrained('composition_versions')->nullOnDelete();
            $table->foreignId('creative_set_id')->nullable()->constrained('creative_sets')->nullOnDelete();
            /** @see \App\Enums\StudioVariantGroupType */
            $table->string('type', 32);
            $table->string('label', 255)->nullable();
            /** @see \App\Models\StudioVariantGroup::STATUS_* */
            $table->string('status', 32)->default('active');
            $table->json('settings_json')->nullable();
            $table->json('target_spec_json')->nullable();
            $table->uuid('shared_mask_asset_id')->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'brand_id']);
            $table->index(['source_composition_id', 'type']);
            $table->index(['creative_set_id', 'sort_order']);
        });
        }

        if (! Schema::hasTable('studio_variant_group_members')) {
            Schema::create('studio_variant_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_variant_group_id')->constrained('studio_variant_groups')->cascadeOnDelete();
            $table->foreignId('composition_id')->nullable()->constrained('compositions')->cascadeOnDelete();
            $table->string('slot_key', 64)->nullable();
            $table->string('label', 255)->nullable();
            /** @see \App\Models\StudioVariantGroupMember::STATUS_* */
            $table->string('status', 32)->default('draft');
            /** @see \App\Models\StudioVariantGroupMember::GENERATION_* */
            $table->string('generation_status', 32)->nullable();
            $table->json('spec_json')->nullable();
            // Logical link to generation_job_items; optional DB FK in a later deploy if desired (avoid circular create order).
            $table->unsignedBigInteger('generation_job_item_id')->nullable()->index();
            $table->uuid('result_asset_id')->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['studio_variant_group_id', 'sort_order'], 'svg_members_group_sort_idx');
            $table->index('composition_id');
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_variant_group_members');
        Schema::dropIfExists('studio_variant_groups');
    }
};
