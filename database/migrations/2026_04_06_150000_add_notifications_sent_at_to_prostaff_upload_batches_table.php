<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotent prostaff batch notifications: distinguish "claimed" vs "recipients notified".
     */
    public function up(): void
    {
        Schema::table('prostaff_upload_batches', function (Blueprint $table) {
            $table->timestamp('notifications_sent_at')->nullable()->after('processed_at');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::table('prostaff_upload_batches')
                ->whereNotNull('processed_at')
                ->whereNull('notifications_sent_at')
                ->update(['notifications_sent_at' => DB::raw('processed_at')]);
        } else {
            foreach (DB::table('prostaff_upload_batches')->whereNotNull('processed_at')->whereNull('notifications_sent_at')->get(['id', 'processed_at']) as $row) {
                DB::table('prostaff_upload_batches')->where('id', $row->id)->update([
                    'notifications_sent_at' => $row->processed_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('prostaff_upload_batches', function (Blueprint $table) {
            $table->dropColumn('notifications_sent_at');
        });
    }
};
