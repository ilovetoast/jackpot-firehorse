<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('incubation_target_plan_key', 64)->nullable()->after('incubated_by_agency_id');
            $table->timestamp('incubation_extension_requested_at')->nullable()->after('incubation_target_plan_key');
            $table->text('incubation_extension_request_note')->nullable()->after('incubation_extension_requested_at');
            $table->timestamp('incubation_locked_at')->nullable()->after('incubation_extension_request_note');
        });

        Schema::table('agency_tiers', function (Blueprint $table) {
            $table->unsignedInteger('max_support_extension_days')->nullable()->after('incubation_window_days');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'incubation_target_plan_key',
                'incubation_extension_requested_at',
                'incubation_extension_request_note',
                'incubation_locked_at',
            ]);
        });

        Schema::table('agency_tiers', function (Blueprint $table) {
            $table->dropColumn('max_support_extension_days');
        });
    }
};
