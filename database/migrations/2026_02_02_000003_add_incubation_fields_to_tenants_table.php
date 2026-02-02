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
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('incubated_at')->nullable()->after('agency_approved_by');
            $table->timestamp('incubation_expires_at')->nullable()->after('incubated_at');
            $table->foreignId('incubated_by_agency_id')->nullable()->constrained('tenants')->nullOnDelete()->after('incubation_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['incubated_by_agency_id']);
            $table->dropColumn(['incubated_at', 'incubation_expires_at', 'incubated_by_agency_id']);
        });
    }
};
