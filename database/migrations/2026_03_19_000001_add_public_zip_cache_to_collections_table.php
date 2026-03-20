<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->string('public_zip_path', 500)->nullable()->after('is_public');
            $table->timestamp('public_zip_built_at')->nullable()->after('public_zip_path');
            $table->unsignedInteger('public_zip_asset_count')->nullable()->after('public_zip_built_at');
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn(['public_zip_path', 'public_zip_built_at', 'public_zip_asset_count']);
        });
    }
};
