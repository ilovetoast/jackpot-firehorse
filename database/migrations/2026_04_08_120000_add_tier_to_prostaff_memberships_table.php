<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Future: prostaff tiers for targets, perks, segmentation (no business logic in this migration).
     */
    public function up(): void
    {
        Schema::table('prostaff_memberships', function (Blueprint $table) {
            $table->string('tier')->nullable()->after('period_start')->index();
        });
    }

    public function down(): void
    {
        Schema::table('prostaff_memberships', function (Blueprint $table) {
            $table->dropColumn('tier');
        });
    }
};
