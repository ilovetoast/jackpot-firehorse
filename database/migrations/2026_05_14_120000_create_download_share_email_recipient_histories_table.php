<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_share_email_recipient_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('recipient_email', 255);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'recipient_email'], 'download_share_email_hist_unique');
            $table->index(['tenant_id', 'user_id', 'last_sent_at'], 'download_share_email_hist_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_share_email_recipient_histories');
    }
};
