<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'severity')) {
                $table->string('severity')->nullable()->after('assigned_team');
            }
            if (! Schema::hasColumn('tickets', 'environment')) {
                $table->string('environment')->nullable()->after('severity');
            }
            if (! Schema::hasColumn('tickets', 'component')) {
                $table->string('component')->nullable()->after('environment');
            }
        });

        Schema::table('tickets', function (Blueprint $table) {
            // Add indexes only when missing to keep migration idempotent.
            $hasSeverityIndex = $this->hasIndex('tickets', 'tickets_severity_index');
            $hasEnvironmentIndex = $this->hasIndex('tickets', 'tickets_environment_index');
            $hasComponentIndex = $this->hasIndex('tickets', 'tickets_component_index');

            if (Schema::hasColumn('tickets', 'severity') && ! $hasSeverityIndex) {
                $table->index('severity');
            }
            if (Schema::hasColumn('tickets', 'environment') && ! $hasEnvironmentIndex) {
                $table->index('environment');
            }
            if (Schema::hasColumn('tickets', 'component') && ! $hasComponentIndex) {
                $table->index('component');
            }
        });
    }

    protected function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $databaseName = $connection->getDatabaseName();

        $result = DB::select(
            "SELECT COUNT(*) as count FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?",
            [$databaseName, $table, $indexName]
        );

        return (int) ($result[0]->count ?? 0) > 0;
    }

    public function down(): void
    {
        if (! Schema::hasTable('tickets')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            if (Schema::hasColumn('tickets', 'severity')) {
                $table->dropIndex(['severity']);
            }
            if (Schema::hasColumn('tickets', 'environment')) {
                $table->dropIndex(['environment']);
            }
            if (Schema::hasColumn('tickets', 'component')) {
                $table->dropIndex(['component']);
            }
        });

        Schema::table('tickets', function (Blueprint $table) {
            $drop = [];
            if (Schema::hasColumn('tickets', 'severity')) {
                $drop[] = 'severity';
            }
            if (Schema::hasColumn('tickets', 'environment')) {
                $drop[] = 'environment';
            }
            if (Schema::hasColumn('tickets', 'component')) {
                $drop[] = 'component';
            }
            if (! empty($drop)) {
                $table->dropColumn($drop);
            }
        });
    }
};
