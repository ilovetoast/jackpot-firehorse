<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_composition_video_export_jobs', function (Blueprint $table) {
            $table->string('render_mode', 32)
                ->default('legacy_bitmap')
                ->after('composition_id')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('studio_composition_video_export_jobs', function (Blueprint $table) {
            $table->dropColumn('render_mode');
        });
    }
};
