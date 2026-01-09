<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->onDelete('cascade'); // References tickets
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null'); // User who created the message
            $table->text('body'); // Message content
            $table->boolean('is_internal')->default(false)->index(); // Internal vs public note
            $table->timestamps();

            // Indexes
            $table->index('ticket_id');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
    }
};
