<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('tenant_stripe_invoice_admin_hides');
    }

    public function down(): void
    {
        // Intentionally empty: admin-only hide list was removed; do not recreate.
    }
};
