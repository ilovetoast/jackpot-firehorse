<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Add NOT NULL constraint to tenants.uuid.
 *
 * Backfills any null UUIDs before applying the constraint.
 * Ensures uuid is string, unique, and NOT NULL for canonical storage paths.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Backfill null UUIDs before applying NOT NULL
        $tenants = \DB::table('tenants')->whereNull('uuid')->get();
        foreach ($tenants as $tenant) {
            \DB::table('tenants')->where('id', $tenant->id)->update([
                'uuid' => (string) Str::uuid(),
            ]);
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->change();
        });
    }
};
