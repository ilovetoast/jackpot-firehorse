<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_agencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agency_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('role')->default('agency_admin');
            $table->json('brand_assignments')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'agency_tenant_id']);
            $table->index('agency_tenant_id');
        });

        Schema::table('tenant_user', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_user', 'is_agency_managed')) {
                $table->boolean('is_agency_managed')->default(false)->after('role');
            }
            if (! Schema::hasColumn('tenant_user', 'agency_tenant_id')) {
                $table->foreignId('agency_tenant_id')->nullable()->after('is_agency_managed')->constrained('tenants')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_user', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_user', 'agency_tenant_id')) {
                $table->dropForeign(['agency_tenant_id']);
                $table->dropColumn('agency_tenant_id');
            }
            if (Schema::hasColumn('tenant_user', 'is_agency_managed')) {
                $table->dropColumn('is_agency_managed');
            }
        });

        Schema::dropIfExists('tenant_agencies');
    }
};
