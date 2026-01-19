<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds billing_status field for accounting purposes.
     * When a plan is manually assigned without Stripe connection,
     * billing_status should be set to 'comped' to indicate no revenue.
     * 
     * Values:
     * - null/paid: Normal billing (Stripe subscription)
     * - comped: Account is comped/free (no revenue, expenses still apply)
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Add billing_status if it doesn't exist
            if (!Schema::hasColumn('tenants', 'billing_status')) {
                $table->string('billing_status')->nullable()->after('manual_plan_override')
                    ->comment('Billing status for accounting: null=paid (Stripe), comped=free account, trial=trial period');
            }
            
            // Add billing_status_expires_at if it doesn't exist
            if (!Schema::hasColumn('tenants', 'billing_status_expires_at')) {
                $table->timestamp('billing_status_expires_at')->nullable()->after('billing_status')
                    ->comment('Expiration date for trial/comped accounts');
            }
            
            // Add equivalent_plan_value if it doesn't exist
            if (!Schema::hasColumn('tenants', 'equivalent_plan_value')) {
                $table->decimal('equivalent_plan_value', 10, 2)->nullable()->after('billing_status_expires_at')
                    ->comment('Sales insight only - NOT real revenue');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $columnsToDrop = [];
            
            // Only drop columns that exist
            if (Schema::hasColumn('tenants', 'equivalent_plan_value')) {
                $columnsToDrop[] = 'equivalent_plan_value';
            }
            
            if (Schema::hasColumn('tenants', 'billing_status_expires_at')) {
                $columnsToDrop[] = 'billing_status_expires_at';
            }
            
            if (Schema::hasColumn('tenants', 'billing_status')) {
                $columnsToDrop[] = 'billing_status';
            }
            
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
