<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Canonical color authority: only user-defined colors influence scoring/conflicts.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->boolean('primary_color_user_defined')->default(false)->after('primary_color');
            $table->boolean('secondary_color_user_defined')->default(false)->after('secondary_color');
            $table->boolean('accent_color_user_defined')->default(false)->after('accent_color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn([
                'primary_color_user_defined',
                'secondary_color_user_defined',
                'accent_color_user_defined',
            ]);
        });
    }
};
