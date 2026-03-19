<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification collapsing: group by type + brand + date.
 * Adds group_key, count, latest_at, meta for expandable grouped view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('group_key')->nullable()->after('type');
            $table->unsignedInteger('count')->default(1)->after('group_key');
            $table->timestamp('latest_at')->nullable()->after('count');
            $table->json('meta')->nullable()->after('data');
        });

        // Backfill: legacy rows get count=1, latest_at=created_at
        \DB::table('notifications')
            ->whereNull('group_key')
            ->update([
                'count' => 1,
                'latest_at' => \DB::raw('created_at'),
            ]);

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['user_id', 'group_key']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'group_key']);
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['group_key', 'count', 'latest_at', 'meta']);
        });
    }
};
