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
        Schema::create('ticket_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->onDelete('cascade');
            $table->string('linkable_type'); // Polymorphic type (e.g., App\Models\ActivityEvent, App\Models\Ticket)
            $table->unsignedBigInteger('linkable_id'); // Polymorphic ID
            $table->string('link_type'); // Type of link: event, error_log, ticket
            $table->timestamps();

            // Indexes
            $table->index('ticket_id');
            $table->index(['linkable_type', 'linkable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_links');
    }
};
