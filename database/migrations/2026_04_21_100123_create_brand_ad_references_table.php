<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * brand_ad_references
 *
 * A simple user-curated gallery of "ads we want to emulate" per brand. Each
 * row points at a DAM Asset (uploaded by the brand owner) plus optional
 * free-form notes. Order is explicit so users can drag-sort the gallery;
 * the UI shows lowest `order` first.
 *
 * Why not stash these in `brand.settings->ad_style->references` JSON?
 *   - Each reference points at a real Asset row, and we want Asset deletes
 *     to cascade cleanly (cascadeOnDelete FK). Hand-rolling that in JSON
 *     would mean ad-hoc cleanup every time someone deletes a DAM asset.
 *   - The list can grow to dozens of images once teams start curating.
 *     Dedicated rows keep the brand settings JSON lean.
 *   - Per-reference notes + future per-reference signal extraction (colors,
 *     typography, composition) benefit from a structured table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brand_ad_references')) {
            return;
        }

        Schema::create('brand_ad_references', function (Blueprint $table) {
            $table->id();

            $table->foreignId('brand_id')
                ->constrained('brands')
                ->cascadeOnDelete();

            $table->uuid('asset_id');
            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->cascadeOnDelete();

            $table->text('notes')->nullable();
            $table->unsignedInteger('display_order')->default(0);

            $table->timestamps();

            $table->index(['brand_id', 'display_order'], 'bar_brand_order_index');
            $table->unique(['brand_id', 'asset_id'], 'bar_brand_asset_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_ad_references');
    }
};
