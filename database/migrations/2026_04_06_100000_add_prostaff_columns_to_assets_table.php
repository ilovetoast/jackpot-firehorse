<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creator / prostaff Phase 3: tag assets uploaded by active prostaff members.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('assets', 'submitted_by_prostaff')) {
            Schema::table('assets', function (Blueprint $table) {
                $table->boolean('submitted_by_prostaff')->default(false)->after('approval_status');
            });
        }

        if (! Schema::hasColumn('assets', 'prostaff_user_id')) {
            Schema::table('assets', function (Blueprint $table) {
                $table->foreignId('prostaff_user_id')->nullable()->after('submitted_by_prostaff')->constrained('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('assets', 'prostaff_user_id')) {
            Schema::table('assets', function (Blueprint $table) {
                $table->dropForeign(['prostaff_user_id']);
            });
        }

        $drop = array_values(array_filter(
            ['submitted_by_prostaff', 'prostaff_user_id'],
            fn (string $column) => Schema::hasColumn('assets', $column)
        ));

        if ($drop !== []) {
            Schema::table('assets', function (Blueprint $table) use ($drop) {
                $table->dropColumn($drop);
            });
        }
    }
};
