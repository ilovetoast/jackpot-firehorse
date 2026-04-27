<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Engineering round-robin bucket: users eligible for automatic assignment of
     * {@see \App\Enums\TicketType::INTERNAL} (engineering) tickets. When empty, uses
     * config('tickets.engineering_round_robin_default_user_ids', [1]).
     */
    public function up(): void
    {
        Schema::create('engineering_round_robin_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineering_round_robin_users');
    }
};
