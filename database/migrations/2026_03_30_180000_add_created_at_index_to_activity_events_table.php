<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Global admin queries filter/order by created_at; composite indexes start with tenant_id,
     * so an unfiltered ORDER BY created_at could not use them. Supports range filters + ordering.
     */
    public function up(): void
    {
        Schema::table('activity_events', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('activity_events', function (Blueprint $table) {
            $table->dropIndex('activity_events_created_at_index');
        });
    }
};
