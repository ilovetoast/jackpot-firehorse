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
            $table->string('billing_status')->nullable()->after('manual_plan_override')
                ->comment('Billing status for accounting: null=paid (Stripe), comped=free account');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'billing_status',
                'billing_status_expires_at',
                'equivalent_plan_value',
            ]);
        });
    }
};
