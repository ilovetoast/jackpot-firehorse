<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemoveBrandUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'brand:remove-user {user_email} {brand_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove a user from a brand by deleting the brand_user pivot record';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $userEmail = $this->argument('user_email');
        $brandId = $this->argument('brand_id');

        $user = \App\Models\User::where('email', $userEmail)->first();
        if (!$user) {
            $this->error("User with email '{$userEmail}' not found.");
            return 1;
        }

        $brand = \App\Models\Brand::find($brandId);
        if (!$brand) {
            $this->error("Brand with ID '{$brandId}' not found.");
            return 1;
        }

        // Check if pivot record exists
        $pivot = DB::table('brand_user')
            ->where('user_id', $user->id)
            ->where('brand_id', $brandId)
            ->first();

        if (!$pivot) {
            $this->warn("No brand_user record found for user '{$userEmail}' and brand ID '{$brandId}'.");
            return 0;
        }

        $this->info("Found brand_user record:");
        $this->line("  ID: {$pivot->id}");
        $this->line("  User: {$user->name} ({$user->email})");
        $this->line("  Brand: {$brand->name} (ID: {$brand->id})");
        $this->line("  Role: {$pivot->role}");

        if ($this->confirm('Do you want to delete this record?', true)) {
            DB::table('brand_user')
                ->where('id', $pivot->id)
                ->delete();

            $this->info("Successfully removed user '{$userEmail}' from brand '{$brand->name}'.");
            return 0;
        }

        $this->info('Operation cancelled.');
        return 0;
    }
}
