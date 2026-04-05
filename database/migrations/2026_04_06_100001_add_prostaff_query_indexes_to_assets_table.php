<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dashboards / filters / analytics: scope by brand + prostaff flag.
     * Note: prostaff_user_id is already indexed by the foreign key on that column.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->index(['brand_id', 'submitted_by_prostaff']);
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['brand_id', 'submitted_by_prostaff']);
        });
    }
};
