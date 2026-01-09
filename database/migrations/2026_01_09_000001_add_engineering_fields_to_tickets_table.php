<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Engineering-specific fields (only for internal engineering tickets)
            $table->string('severity')->nullable()->after('assigned_team'); // P0, P1, P2, P3
            $table->string('environment')->nullable()->after('severity'); // production, staging, development
            $table->string('component')->nullable()->after('environment'); // api, web, worker, billing, integrations
            
            // Indexes for filtering
            $table->index('severity');
            $table->index('environment');
            $table->index('component');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['severity']);
            $table->dropIndex(['environment']);
            $table->dropIndex(['component']);
            $table->dropColumn(['severity', 'environment', 'component']);
        });
    }
};
