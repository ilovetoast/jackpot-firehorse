<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase MI-1: Brand Membership Integrity Diagnostic Command
 * 
 * Detects and reports:
 * - Duplicate brand_user rows (same user/brand combination)
 * - Multiple active memberships (same user/brand with multiple removed_at IS NULL)
 * 
 * This command reports issues but does NOT auto-fix them.
 * Manual review required before fixing data integrity issues.
 */
class DiagnoseBrandMembership extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'brand:diagnose-membership 
                            {--tenant-id= : Filter by specific tenant ID}
                            {--fix : Attempt to fix issues (use with caution)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose brand membership integrity issues (duplicates, multiple active memberships)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenantId = $this->option('tenant-id');
        $shouldFix = $this->option('fix');
        
        $this->info('Phase MI-1: Brand Membership Integrity Diagnostic');
        $this->newLine();
        
        // Check 1: Duplicate brand_user rows (same user_id + brand_id)
        $this->info('Checking for duplicate brand_user rows...');
        $duplicates = DB::table('brand_user')
            ->select('brand_id', 'user_id', DB::raw('COUNT(*) as count'))
            ->groupBy('brand_id', 'user_id')
            ->having('count', '>', 1)
            ->when($tenantId, function ($query) use ($tenantId) {
                return $query->join('brands', 'brand_user.brand_id', '=', 'brands.id')
                    ->where('brands.tenant_id', $tenantId);
            })
            ->get();
        
        if ($duplicates->isEmpty()) {
            $this->info('✓ No duplicate brand_user rows found.');
        } else {
            $this->warn("⚠ Found {$duplicates->count()} duplicate brand_user combinations:");
            foreach ($duplicates as $dup) {
                $brand = DB::table('brands')->find($dup->brand_id);
                $user = DB::table('users')->find($dup->user_id);
                $this->line("  - Brand: {$brand->name} (ID: {$dup->brand_id}), User: {$user->email} (ID: {$dup->user_id}), Count: {$dup->count}");
                
                // Show details of all rows
                $rows = DB::table('brand_user')
                    ->where('brand_id', $dup->brand_id)
                    ->where('user_id', $dup->user_id)
                    ->get();
                
                foreach ($rows as $row) {
                    $removedStatus = $row->removed_at ? "REMOVED ({$row->removed_at})" : "ACTIVE";
                    $this->line("    Row ID {$row->id}: Role={$row->role}, Status={$removedStatus}, Created={$row->created_at}");
                }
            }
        }
        
        $this->newLine();
        
        // Check 2: Multiple active memberships (same user/brand with multiple removed_at IS NULL)
        $this->info('Checking for multiple active memberships...');
        $multipleActive = DB::table('brand_user')
            ->select('brand_id', 'user_id', DB::raw('COUNT(*) as active_count'))
            ->whereNull('removed_at')
            ->groupBy('brand_id', 'user_id')
            ->having('active_count', '>', 1)
            ->when($tenantId, function ($query) use ($tenantId) {
                return $query->join('brands', 'brand_user.brand_id', '=', 'brands.id')
                    ->where('brands.tenant_id', $tenantId);
            })
            ->get();
        
        if ($multipleActive->isEmpty()) {
            $this->info('✓ No multiple active memberships found.');
        } else {
            $this->error("✗ Found {$multipleActive->count()} user/brand combinations with multiple active memberships:");
            foreach ($multipleActive as $multi) {
                $brand = DB::table('brands')->find($multi->brand_id);
                $user = DB::table('users')->find($multi->user_id);
                $this->line("  - Brand: {$brand->name} (ID: {$multi->brand_id}), User: {$user->email} (ID: {$multi->user_id}), Active Count: {$multi->active_count}");
                
                // Show details of all active rows
                $rows = DB::table('brand_user')
                    ->where('brand_id', $multi->brand_id)
                    ->where('user_id', $multi->user_id)
                    ->whereNull('removed_at')
                    ->orderBy('created_at', 'asc')
                    ->get();
                
                foreach ($rows as $index => $row) {
                    $this->line("    Active Row #{$index + 1} (ID {$row->id}): Role={$row->role}, Created={$row->created_at}, Updated={$row->updated_at}");
                }
                
                if ($shouldFix) {
                    $this->warn("    Attempting to fix: Keeping most recent active membership, soft-deleting others...");
                    // Keep the most recent one, soft-delete the rest
                    $keepRow = $rows->sortByDesc('updated_at')->first();
                    $rowsToFix = $rows->where('id', '!=', $keepRow->id);
                    
                    foreach ($rowsToFix as $rowToFix) {
                        DB::table('brand_user')
                            ->where('id', $rowToFix->id)
                            ->update([
                                'removed_at' => now(),
                                'updated_at' => now(),
                            ]);
                        $this->line("      ✓ Soft-deleted row ID {$rowToFix->id}");
                    }
                }
            }
        }
        
        $this->newLine();
        
        // Check 3: Orphaned memberships (user or brand deleted but pivot remains)
        $this->info('Checking for orphaned memberships...');
        $orphanedQuery = DB::table('brand_user')
            ->leftJoin('users', 'brand_user.user_id', '=', 'users.id')
            ->leftJoin('brands', 'brand_user.brand_id', '=', 'brands.id')
            ->where(function ($query) {
                $query->whereNull('users.id')
                      ->orWhereNull('brands.id');
            });
        
        if ($tenantId) {
            $orphanedQuery->where('brands.tenant_id', $tenantId);
        }
        
        $orphaned = $orphanedQuery->select('brand_user.*')->get();
        
        if ($orphaned->isEmpty()) {
            $this->info('✓ No orphaned memberships found.');
        } else {
            $this->warn("⚠ Found {$orphaned->count()} orphaned memberships (user or brand deleted):");
            foreach ($orphaned as $orphan) {
                $this->line("  - Row ID {$orphan->id}: brand_id={$orphan->brand_id}, user_id={$orphan->user_id}");
            }
        }
        
        $this->newLine();
        
        // Summary
        $hasIssues = !$duplicates->isEmpty() || !$multipleActive->isEmpty() || !$orphaned->isEmpty();
        
        if ($hasIssues) {
            $this->warn('Summary: Issues detected. Review the output above.');
            if (!$shouldFix) {
                $this->info('Tip: Run with --fix flag to attempt automatic fixes (use with caution).');
            }
            return 1;
        } else {
            $this->info('✓ All checks passed. Brand membership integrity is clean.');
            return 0;
        }
    }
}
