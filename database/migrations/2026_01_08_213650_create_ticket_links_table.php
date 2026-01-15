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
        if (Schema::hasTable('ticket_links')) {
            // Add foreign key constraint only if tickets table exists and constraint doesn't exist
            if (Schema::hasTable('tickets')) {
                Schema::table('ticket_links', function (Blueprint $table) {
                    // Check if foreign key doesn't exist before adding
                    $foreignKeys = Schema::getConnection()
                        ->getDoctrineSchemaManager()
                        ->listTableForeignKeys('ticket_links');
                    
                    $hasForeignKey = false;
                    foreach ($foreignKeys as $foreignKey) {
                        if ($foreignKey->getName() === 'ticket_links_ticket_id_foreign') {
                            $hasForeignKey = true;
                            break;
                        }
                    }
                    
                    if (!$hasForeignKey) {
                        $table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('cascade');
                    }
                });
            }
            return;
        }

        Schema::create('ticket_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->string('linkable_type'); // Polymorphic type (e.g., App\Models\ActivityEvent, App\Models\Ticket)
            $table->unsignedBigInteger('linkable_id'); // Polymorphic ID
            $table->string('link_type'); // Type of link: event, error_log, ticket
            $table->timestamps();

            // Indexes
            $table->index('ticket_id');
            $table->index(['linkable_type', 'linkable_id']);
        });

        // Add foreign key constraint only if tickets table exists
        if (Schema::hasTable('tickets')) {
            Schema::table('ticket_links', function (Blueprint $table) {
                $table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_links');
    }
};
