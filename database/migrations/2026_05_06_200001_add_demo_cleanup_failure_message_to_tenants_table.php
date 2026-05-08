<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'demo_cleanup_failure_message')) {
                $after = Schema::hasColumn('tenants', 'demo_clone_failure_message')
                    ? 'demo_clone_failure_message'
                    : 'demo_notes';
                $table->text('demo_cleanup_failure_message')->nullable()->after($after);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'demo_cleanup_failure_message')) {
                $table->dropColumn('demo_cleanup_failure_message');
            }
        });
    }
};
