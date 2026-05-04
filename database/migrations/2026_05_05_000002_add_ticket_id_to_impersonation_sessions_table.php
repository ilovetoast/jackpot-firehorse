<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('impersonation_sessions', function (Blueprint $table) {
            $table->string('ticket_id', 128)->nullable()->after('reason');
            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('impersonation_sessions', function (Blueprint $table) {
            $table->dropIndex(['ticket_id']);
            $table->dropColumn('ticket_id');
        });
    }
};
