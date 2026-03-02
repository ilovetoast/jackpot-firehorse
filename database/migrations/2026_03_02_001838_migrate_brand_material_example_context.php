<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        \DB::table('brand_model_version_assets')
            ->where('builder_context', 'brand_material_example')
            ->update(['builder_context' => 'brand_material']);
    }

    public function down(): void
    {
        \DB::table('brand_model_version_assets')
            ->where('builder_context', 'brand_material')
            ->update(['builder_context' => 'brand_material_example']);
    }
};
