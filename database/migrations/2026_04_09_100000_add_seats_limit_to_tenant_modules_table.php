<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9: optional seat cap for Creator module (no enforcement logic yet).
     */
    public function up(): void
    {
        Schema::table('tenant_modules', function (Blueprint $table) {
            $table->unsignedInteger('seats_limit')->nullable()->after('granted_by_admin');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_modules', function (Blueprint $table) {
            $table->dropColumn('seats_limit');
        });
    }
};
