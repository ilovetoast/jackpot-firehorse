<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_layer_extraction_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('brand_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('composition_id')->index();
            $table->string('source_layer_id', 128);
            $table->uuid('source_asset_id');
            $table->string('status', 32)->index();
            $table->string('provider', 64)->nullable();
            $table->string('model', 128)->nullable();
            $table->text('candidates_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_layer_extraction_sessions');
    }
};
