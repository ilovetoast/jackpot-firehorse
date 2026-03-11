<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Support round-robin bucket: users eligible for automatic ticket assignment.
     * Only users with site_support (or site_admin/site_owner) should be added.
     * When empty, falls back to config('tickets.round_robin_default_user_ids', [1]).
     */
    public function up(): void
    {
        Schema::create('support_round_robin_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_round_robin_users');
    }
};
