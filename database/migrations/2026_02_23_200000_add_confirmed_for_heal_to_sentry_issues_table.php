<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sentry_issues', function (Blueprint $table) {
            $table->boolean('confirmed_for_heal')->default(false)->after('selected_for_heal');
        });
    }

    public function down(): void
    {
        Schema::table('sentry_issues', function (Blueprint $table) {
            $table->dropColumn('confirmed_for_heal');
        });
    }
};
