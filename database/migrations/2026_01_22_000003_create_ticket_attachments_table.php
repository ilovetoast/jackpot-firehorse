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
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->onDelete('cascade'); // References tickets
            $table->foreignId('ticket_message_id')->nullable()->constrained()->onDelete('cascade'); // Optional: attachment to specific message
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null'); // User who uploaded the attachment
            $table->string('file_path'); // S3 path to file
            $table->string('file_name'); // Original filename
            $table->bigInteger('file_size')->nullable(); // File size in bytes
            $table->string('mime_type')->nullable(); // MIME type
            $table->timestamps();

            // Indexes
            $table->index('ticket_id');
            $table->index('ticket_message_id');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
    }
};
