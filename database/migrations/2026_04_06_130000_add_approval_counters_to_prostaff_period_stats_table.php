<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Future: approval / rejection rates without another schema pass.
     */
    public function up(): void
    {
        Schema::table('prostaff_period_stats', function (Blueprint $table) {
            $table->unsignedInteger('approved_uploads')->default(0)->after('actual_uploads');
            $table->unsignedInteger('rejected_uploads')->default(0)->after('approved_uploads');
        });
    }

    public function down(): void
    {
        Schema::table('prostaff_period_stats', function (Blueprint $table) {
            $table->dropColumn(['approved_uploads', 'rejected_uploads']);
        });
    }
};
