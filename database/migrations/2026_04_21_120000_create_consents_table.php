<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('purpose', 64);
            $table->boolean('granted')->default(true);
            $table->string('policy_version', 16)->default('1');
            $table->timestamp('granted_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'granted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
