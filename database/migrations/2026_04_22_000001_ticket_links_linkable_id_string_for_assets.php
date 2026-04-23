<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asset polymorphs use UUID string IDs; widen linkable_id so tickets can link to assets.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ticket_links')) {
            return;
        }

        Schema::table('ticket_links', function (Blueprint $table) {
            $table->string('linkable_id', 64)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ticket_links')) {
            return;
        }

        Schema::table('ticket_links', function (Blueprint $table) {
            $table->unsignedBigInteger('linkable_id')->change();
        });
    }
};
