<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'demo_clone_failure_message')) {
                $table->text('demo_clone_failure_message')->nullable()->after('demo_notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'demo_clone_failure_message')) {
                $table->dropColumn('demo_clone_failure_message');
            }
        });
    }
};
