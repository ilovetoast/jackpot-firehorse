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
        Schema::create('brand_ticket', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->onDelete('cascade'); // References brands
            $table->foreignId('ticket_id')->constrained()->onDelete('cascade'); // References tickets
            $table->timestamps();

            // Unique constraint: a brand-ticket combination can only exist once
            $table->unique(['brand_id', 'ticket_id']);
            
            // Indexes
            $table->index('brand_id');
            $table->index('ticket_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_ticket');
    }
};
