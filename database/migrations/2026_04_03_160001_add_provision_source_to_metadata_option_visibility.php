<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metadata_option_visibility', function (Blueprint $table) {
            $table->string('provision_source', 32)->nullable()->after('is_hidden');
        });
    }

    public function down(): void
    {
        Schema::table('metadata_option_visibility', function (Blueprint $table) {
            $table->dropColumn('provision_source');
        });
    }
};
