<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant- and system-scoped application errors that are not queue failures or PHP exceptions.
 *
 * Examples: AI provider overload, rate limits, validation failures surfaced as "failed" agent runs.
 * Complements system_incidents (asset/job pipeline) and failed_jobs (hard job failures).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_error_events', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 50)->index();
            $table->string('source_id', 64)->nullable()->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('category', 50)->index();
            $table->string('code', 80)->nullable()->index();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['category', 'created_at']);
        });

        if (Schema::hasTable('tenants')) {
            Schema::table('application_error_events', function (Blueprint $table) {
                $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            });
        }
        if (Schema::hasTable('users')) {
            Schema::table('application_error_events', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('application_error_events');
    }
};
