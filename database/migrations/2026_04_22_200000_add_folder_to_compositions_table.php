<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compositions', function (Blueprint $table) {
            $table->string('folder', 64)->nullable()->after('name');
            $table->index(['brand_id', 'folder'], 'compositions_brand_id_folder_index');
        });
    }

    public function down(): void
    {
        Schema::table('compositions', function (Blueprint $table) {
            $table->dropIndex('compositions_brand_id_folder_index');
        });
        Schema::table('compositions', function (Blueprint $table) {
            $table->dropColumn('folder');
        });
    }
};
