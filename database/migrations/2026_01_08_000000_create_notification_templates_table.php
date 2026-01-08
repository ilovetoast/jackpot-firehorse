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
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g., 'invite_member', 'password_reset'
            $table->string('name'); // Display name
            $table->string('subject'); // Email subject line
            $table->text('body_html'); // HTML email body
            $table->text('body_text')->nullable(); // Plain text version
            $table->json('variables')->nullable(); // Available variables for this template
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
