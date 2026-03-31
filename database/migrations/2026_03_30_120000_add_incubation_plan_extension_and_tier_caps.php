<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'incubation_target_plan_key')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->string('incubation_target_plan_key', 64)->nullable()->after('incubated_by_agency_id');
            });
        }

        if (! Schema::hasColumn('tenants', 'incubation_extension_requested_at')) {
            Schema::table('tenants', function (Blueprint $table) {
                $after = Schema::hasColumn('tenants', 'incubation_target_plan_key')
                    ? 'incubation_target_plan_key'
                    : 'incubated_by_agency_id';
                $table->timestamp('incubation_extension_requested_at')->nullable()->after($after);
            });
        }

        if (! Schema::hasColumn('tenants', 'incubation_extension_request_note')) {
            Schema::table('tenants', function (Blueprint $table) {
                $after = Schema::hasColumn('tenants', 'incubation_extension_requested_at')
                    ? 'incubation_extension_requested_at'
                    : (Schema::hasColumn('tenants', 'incubation_target_plan_key')
                        ? 'incubation_target_plan_key'
                        : 'incubated_by_agency_id');
                $table->text('incubation_extension_request_note')->nullable()->after($after);
            });
        }

        if (! Schema::hasColumn('tenants', 'incubation_locked_at')) {
            Schema::table('tenants', function (Blueprint $table) {
                $after = Schema::hasColumn('tenants', 'incubation_extension_request_note')
                    ? 'incubation_extension_request_note'
                    : (Schema::hasColumn('tenants', 'incubation_extension_requested_at')
                        ? 'incubation_extension_requested_at'
                        : 'incubated_by_agency_id');
                $table->timestamp('incubation_locked_at')->nullable()->after($after);
            });
        }

        if (! Schema::hasColumn('agency_tiers', 'max_support_extension_days')) {
            Schema::table('agency_tiers', function (Blueprint $table) {
                $table->unsignedInteger('max_support_extension_days')->nullable()->after('incubation_window_days');
            });
        }
    }

    public function down(): void
    {
        $tenantCols = array_filter([
            'incubation_target_plan_key',
            'incubation_extension_requested_at',
            'incubation_extension_request_note',
            'incubation_locked_at',
        ], fn (string $c) => Schema::hasColumn('tenants', $c));

        if ($tenantCols !== []) {
            Schema::table('tenants', function (Blueprint $table) use ($tenantCols) {
                $table->dropColumn($tenantCols);
            });
        }

        if (Schema::hasColumn('agency_tiers', 'max_support_extension_days')) {
            Schema::table('agency_tiers', function (Blueprint $table) {
                $table->dropColumn('max_support_extension_days');
            });
        }
    }
};
