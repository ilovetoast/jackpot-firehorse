<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creator / prostaff — query paths by brand+status and tenant+status.
     */
    public function up(): void
    {
        Schema::table('prostaff_memberships', function (Blueprint $table) {
            $table->index(['brand_id', 'status']);
            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prostaff_memberships', function (Blueprint $table) {
            $table->dropIndex(['brand_id', 'status']);
            $table->dropIndex(['tenant_id', 'status']);
        });
    }
};
