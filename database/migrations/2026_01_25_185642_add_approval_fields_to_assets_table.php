<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase AF-1: Add approval workflow fields to assets table.
 * 
 * Approval status tracks whether an asset requires approval and its current state.
 * - 'not_required': Asset does not require approval (default for users without requires_approval flag)
 * - 'pending': Asset is awaiting approval
 * - 'approved': Asset has been approved
 * - 'rejected': Asset has been rejected
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            if (!Schema::hasColumn('assets', 'approval_status')) {
                $table->enum('approval_status', ['not_required', 'pending', 'approved', 'rejected'])
                    ->default('not_required')
                    ->after('expires_at');
                $table->index('approval_status');
            }
            
            if (!Schema::hasColumn('assets', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approval_status');
            }
            
            if (!Schema::hasColumn('assets', 'approved_by_user_id')) {
                $table->foreignId('approved_by_user_id')
                    ->nullable()
                    ->after('approved_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            
            if (!Schema::hasColumn('assets', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_by_user_id');
            }
            
            if (!Schema::hasColumn('assets', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('rejected_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            if (Schema::hasColumn('assets', 'rejection_reason')) {
                $table->dropColumn('rejection_reason');
            }
            
            if (Schema::hasColumn('assets', 'rejected_at')) {
                $table->dropColumn('rejected_at');
            }
            
            if (Schema::hasColumn('assets', 'approved_by_user_id')) {
                $table->dropForeign(['approved_by_user_id']);
                $table->dropColumn('approved_by_user_id');
            }
            
            if (Schema::hasColumn('assets', 'approved_at')) {
                $table->dropColumn('approved_at');
            }
            
            if (Schema::hasColumn('assets', 'approval_status')) {
                $table->dropIndex(['approval_status']);
                $table->dropColumn('approval_status');
            }
        });
    }
};
