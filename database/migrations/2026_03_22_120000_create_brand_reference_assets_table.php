<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brand_reference_assets')) {
            return;
        }

        Schema::create('brand_reference_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('reference_type', 32)->default('style');
            $table->unsignedTinyInteger('tier')->default(2);
            $table->float('weight', 8, 4)->default(0.6);
            $table->string('category')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['brand_id', 'asset_id']);
            $table->index(['brand_id', 'tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_reference_assets');
    }
};
