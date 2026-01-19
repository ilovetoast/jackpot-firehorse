<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('metadata_fields', function (Blueprint $table) {
            // Add population_mode column with safe default 'manual'
            if (!Schema::hasColumn('metadata_fields', 'population_mode')) {
                $table->string('population_mode')->default('manual')->after('is_internal_only');
            }

            // Add show_on_upload column with safe default true
            if (!Schema::hasColumn('metadata_fields', 'show_on_upload')) {
                $table->boolean('show_on_upload')->default(true)->after('population_mode');
            }

            // Add show_on_edit column with safe default true
            if (!Schema::hasColumn('metadata_fields', 'show_on_edit')) {
                $table->boolean('show_on_edit')->default(true)->after('show_on_upload');
            }

            // Add show_in_filters column with safe default true
            if (!Schema::hasColumn('metadata_fields', 'show_in_filters')) {
                $table->boolean('show_in_filters')->default(true)->after('show_on_edit');
            }

            // Add readonly column with safe default false
            if (!Schema::hasColumn('metadata_fields', 'readonly')) {
                $table->boolean('readonly')->default(false)->after('show_in_filters');
            }
        });

        // Update existing rows to have safe defaults (idempotent)
        // Only update if columns exist and values are null
        if (Schema::hasColumn('metadata_fields', 'population_mode')) {
            DB::table('metadata_fields')
                ->whereNull('population_mode')
                ->orWhere('population_mode', '')
                ->update(['population_mode' => 'manual']);
        }

        if (Schema::hasColumn('metadata_fields', 'show_on_upload')) {
            DB::table('metadata_fields')
                ->whereNull('show_on_upload')
                ->update(['show_on_upload' => true]);
        }

        if (Schema::hasColumn('metadata_fields', 'show_on_edit')) {
            DB::table('metadata_fields')
                ->whereNull('show_on_edit')
                ->update(['show_on_edit' => true]);
        }

        if (Schema::hasColumn('metadata_fields', 'show_in_filters')) {
            DB::table('metadata_fields')
                ->whereNull('show_in_filters')
                ->update(['show_in_filters' => true]);
        }

        if (Schema::hasColumn('metadata_fields', 'readonly')) {
            DB::table('metadata_fields')
                ->whereNull('readonly')
                ->update(['readonly' => false]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('metadata_fields', function (Blueprint $table) {
            if (Schema::hasColumn('metadata_fields', 'readonly')) {
                $table->dropColumn('readonly');
            }
            if (Schema::hasColumn('metadata_fields', 'show_in_filters')) {
                $table->dropColumn('show_in_filters');
            }
            if (Schema::hasColumn('metadata_fields', 'show_on_edit')) {
                $table->dropColumn('show_on_edit');
            }
            if (Schema::hasColumn('metadata_fields', 'show_on_upload')) {
                $table->dropColumn('show_on_upload');
            }
            if (Schema::hasColumn('metadata_fields', 'population_mode')) {
                $table->dropColumn('population_mode');
            }
        });
    }
};
