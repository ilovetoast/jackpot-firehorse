<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('initiator_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('mode', 32);
            $table->text('reason');
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'ended_at']);
            $table->index('expires_at');
        });

        Schema::create('impersonation_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('impersonation_session_id')->constrained('impersonation_sessions')->cascadeOnDelete();
            $table->string('event', 32);
            $table->string('http_method', 16)->nullable();
            $table->string('path', 2048)->nullable();
            $table->string('route_name')->nullable();
            $table->foreignId('initiator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['impersonation_session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_audits');
        Schema::dropIfExists('impersonation_sessions');
    }
};
