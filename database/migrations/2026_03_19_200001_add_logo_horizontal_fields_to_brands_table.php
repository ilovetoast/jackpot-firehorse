<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('logo_horizontal_path')->nullable()->after('logo_dark_id');
            $table->unsignedBigInteger('logo_horizontal_id')->nullable()->after('logo_horizontal_path');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['logo_horizontal_path', 'logo_horizontal_id']);
        });
    }
};
