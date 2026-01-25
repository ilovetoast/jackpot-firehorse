<?php

namespace App\Console\Commands;

use Database\Seeders\PermissionSeeder;
use Illuminate\Console\Command;

class ReseedPermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'permissions:reseed {--fresh : Clear existing permissions and roles before reseeding}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reseed permissions and role assignments. Use --fresh to clear existing data first.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->info('Clearing existing permissions and roles...');
            
            // Clear role-permission relationships
            \DB::table('role_has_permissions')->truncate();
            $this->info('✓ Cleared role-permission relationships');
            
            // Note: We don't delete roles or permissions themselves to avoid breaking existing assignments
            // The seeder uses firstOrCreate, so it will update existing roles/permissions
        }

        $this->info('Seeding permissions and roles...');
        
        $seeder = new PermissionSeeder();
        $seeder->run();
        
        $this->info('✓ Permissions and roles have been reseeded successfully!');
        $this->newLine();
        $this->info('Note: This does not change user role assignments. Only role permissions are updated.');
        
        return Command::SUCCESS;
    }
}
