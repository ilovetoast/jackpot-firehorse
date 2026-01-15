<?php

namespace Database\Seeders;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Development Data Seeder
 * 
 * ⚠️ DEVELOPMENT ONLY - DO NOT RUN IN PRODUCTION ⚠️
 * 
 * Generates large datasets for performance testing, memory leak detection, and UI stress testing.
 * 
 * Usage:
 *   php artisan db:seed --class=DevelopmentDataSeeder
 * 
 * Or explicitly:
 *   php artisan db:seed --class=DevelopmentDataSeeder --force
 * 
 * This seeder:
 * - Creates 1000+ companies with dummy data
 * - Assigns random plans (some with plans, some without)
 * - Generates users based on plan limits (some companies exceed limits)
 * - Creates categories and assets (100-1000+ per company)
 * - Fills in brand data (colors, logos, icons)
 * - Creates ~20 support tickets
 * - Assigns special roles to some users (support, site admin, engineering)
 * 
 * S3 Images:
 * - Uses placeholder paths (no actual S3 uploads)
 * - Paths like: dev-seeder/placeholder.jpg
 * - Prevents gigabytes of S3 storage usage
 */
class DevelopmentDataSeeder extends Seeder
{
    private const COMPANIES_COUNT = 1000;
    private const TICKETS_COUNT = 20;
    
    // Asset counts per company (randomized)
    private const MIN_ASSETS_PER_COMPANY = 100;
    private const MAX_ASSETS_PER_COMPANY = 1000;
    
    // Categories per company
    private const MIN_CATEGORIES_PER_COMPANY = 5;
    private const MAX_CATEGORIES_PER_COMPANY = 20;
    
    // Brands per company
    private const MIN_BRANDS_PER_COMPANY = 1;
    private const MAX_BRANDS_PER_COMPANY = 5;
    
    // Special role percentages
    private const SUPPORT_USER_PERCENT = 0.5; // 0.5% of users
    private const SITE_ADMIN_PERCENT = 0.1; // 0.1% of users
    private const ENGINEERING_PERCENT = 0.2; // 0.2% of users
    
    // Plan distribution
    private const PLAN_DISTRIBUTION = [
        'free' => 0.3,      // 30% free
        'starter' => 0.25,  // 25% starter
        'pro' => 0.25,      // 25% pro
        'enterprise' => 0.2, // 20% enterprise
    ];
    
    // Some companies should exceed limits (for testing)
    private const EXCEED_LIMIT_PERCENT = 0.05; // 5% of companies
    
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Safety check - only run in local/development
        $env = app()->environment();
        if (!in_array($env, ['local', 'development', 'testing'])) {
            $this->command->error('⚠️  This seeder is DEVELOPMENT ONLY!');
            $this->command->error("Current environment: {$env}");
            $this->command->error('Aborting to prevent accidental production data generation.');
            return;
        }
        
        // Confirmation prompt (skip if --force is used or in non-interactive mode)
        $isForced = $this->command->option('force') ?? false;
        if (!$isForced && !$this->command->confirm('This will generate ' . self::COMPANIES_COUNT . '+ companies with extensive test data. Continue?', false)) {
            $this->command->info('Seeder cancelled.');
            return;
        }
        
        $this->command->info('🚀 Starting development data generation...');
        $startTime = microtime(true);
        
        // Get plan limits from config
        $planLimits = $this->getPlanLimits();
        
        // Create companies in chunks to manage memory
        $chunkSize = 50;
        $totalChunks = ceil(self::COMPANIES_COUNT / $chunkSize);
        
        for ($chunk = 0; $chunk < $totalChunks; $chunk++) {
            $offset = $chunk * $chunkSize;
            $count = min($chunkSize, self::COMPANIES_COUNT - $offset);
            
            $this->command->info("Creating companies " . ($offset + 1) . "-" . ($offset + $count) . " of " . self::COMPANIES_COUNT . "...");
            
            for ($i = 0; $i < $count; $i++) {
                $this->createCompanyWithData($planLimits);
            }
            
            // Clear memory
            if ($chunk % 10 === 0) {
                gc_collect_cycles();
            }
        }
        
        // Create support tickets
        $this->command->info('Creating support tickets...');
        $this->createSupportTickets();
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        $this->command->info("✅ Development data generation complete! ({$duration}s)");
        $this->command->info("Created:");
        $this->command->info("  - Companies: " . Tenant::count());
        $this->command->info("  - Brands: " . Brand::count());
        $this->command->info("  - Users: " . User::count());
        $this->command->info("  - Assets: " . Asset::count());
        $this->command->info("  - Categories: " . Category::count());
        $this->command->info("  - Tickets: " . Ticket::count());
    }
    
    /**
     * Create a company with all associated data.
     */
    private function createCompanyWithData(array $planLimits): void
    {
        // Create company
        $companyName = fake()->company() . ' ' . fake()->companySuffix();
        $companySlug = Str::slug($companyName);
        
        // Ensure unique slug
        $originalSlug = $companySlug;
        $counter = 1;
        while (Tenant::where('slug', $companySlug)->exists()) {
            $companySlug = $originalSlug . '-' . $counter;
            $counter++;
        }
        
        $planName = $this->getRandomPlan();
        $planConfig = $planLimits[$planName] ?? $planLimits['free'];
        
        $company = Tenant::create([
            'name' => $companyName,
            'slug' => $companySlug,
            'timezone' => fake()->timezone(),
            'manual_plan_override' => $planName,
            'billing_status' => fake()->randomElement([null, 'comped', 'trial']),
            'stripe_id' => fake()->boolean(30) ? 'cus_' . Str::random(24) : null, // 30% have Stripe
        ]);
        
        // Create storage bucket
        $bucket = StorageBucket::create([
            'tenant_id' => $company->id,
            'name' => 'dev-bucket-' . $company->id,
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        
        // Create brands
        $brandCount = fake()->numberBetween(self::MIN_BRANDS_PER_COMPANY, self::MAX_BRANDS_PER_COMPANY);
        $brands = [];
        
        for ($b = 0; $b < $brandCount; $b++) {
            $brandName = $b === 0 ? $company->name : fake()->company() . ' Brand';
            $brandSlug = Str::slug($brandName);
            
            // Ensure unique slug per tenant
            $originalBrandSlug = $brandSlug;
            $brandCounter = 1;
            while (Brand::where('tenant_id', $company->id)->where('slug', $brandSlug)->exists()) {
                $brandSlug = $originalBrandSlug . '-' . $brandCounter;
                $brandCounter++;
            }
            
            $brand = Brand::create([
                'tenant_id' => $company->id,
                'name' => $brandName,
                'slug' => $brandSlug,
                'is_default' => $b === 0,
                'show_in_selector' => true,
                'primary_color' => fake()->hexColor(),
                'secondary_color' => fake()->hexColor(),
                'accent_color' => fake()->hexColor(),
                'nav_color' => fake()->hexColor(),
                'icon_bg_color' => fake()->hexColor(),
                'icon' => fake()->randomElement(['🎨', '🚀', '💼', '🌟', '⚡', '🎯', '🔥', '💎']),
                'logo_path' => 'dev-seeder/logos/' . Str::uuid() . '.png', // Placeholder path
                'icon_path' => 'dev-seeder/icons/' . Str::uuid() . '.svg', // Placeholder path
                'logo_filter' => fake()->randomElement(['brightness', 'contrast', 'saturate', 'none']),
            ]);
            
            $brands[] = $brand;
        }
        
        // Determine user count (some companies exceed limits)
        $maxUsers = $planConfig['max_users'] ?? PHP_INT_MAX;
        $shouldExceedLimit = fake()->boolean(self::EXCEED_LIMIT_PERCENT * 100);
        $userCount = $shouldExceedLimit 
            ? $maxUsers + fake()->numberBetween(10, 50) // Exceed by 10-50 users
            : fake()->numberBetween(1, min($maxUsers, 200)); // Normal range
        
        // Create owner first
        // Generate unique email for owner
        $ownerEmail = fake()->safeEmail();
        while (User::where('email', $ownerEmail)->exists()) {
            $ownerEmail = 'owner-' . Str::random(8) . '@' . fake()->domainName();
        }
        
        $owner = User::create([
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => $ownerEmail,
            'password' => bcrypt('password'),
            'avatar_url' => 'dev-seeder/avatars/' . Str::uuid() . '.jpg', // Placeholder path
        ]);
        
        $company->users()->attach($owner->id, ['role' => 'owner']);
        
        // Attach owner to default brand
        $brands[0]->users()->attach($owner->id, ['role' => 'admin']);
        
        // Create additional users
        for ($u = 1; $u < $userCount; $u++) {
            // Generate unique email
            $userEmail = fake()->safeEmail();
            while (User::where('email', $userEmail)->exists()) {
                $userEmail = 'user-' . Str::random(8) . '-' . time() . '@' . fake()->domainName();
            }
            
            $user = User::create([
                'first_name' => fake()->firstName(),
                'last_name' => fake()->lastName(),
                'email' => $userEmail,
                'password' => bcrypt('password'),
                'avatar_url' => 'dev-seeder/avatars/' . Str::uuid() . '.jpg', // Placeholder path
            ]);
            
            // Assign role
            $role = fake()->randomElement(['member', 'member', 'member', 'admin']); // 75% member, 25% admin
            $company->users()->attach($user->id, ['role' => $role]);
            
            // Assign to random brands
            $brandsToAssign = fake()->randomElements($brands, fake()->numberBetween(1, min(count($brands), 3)));
            foreach ($brandsToAssign as $brand) {
                $brandRole = fake()->randomElement(['member', 'admin']);
                $brand->users()->attach($user->id, ['role' => $brandRole]);
            }
            
            // Assign special roles (rare) - use correct role names from PermissionSeeder
            if (fake()->boolean(self::SUPPORT_USER_PERCENT * 100)) {
                $user->assignRole('site_support');
            }
            if (fake()->boolean(self::SITE_ADMIN_PERCENT * 100)) {
                $user->assignRole('site_admin');
            }
            if (fake()->boolean(self::ENGINEERING_PERCENT * 100)) {
                $user->assignRole('site_engineering');
            }
        }
        
        // Create categories for each brand
        foreach ($brands as $brand) {
            $categoryCount = fake()->numberBetween(self::MIN_CATEGORIES_PER_COMPANY, self::MAX_CATEGORIES_PER_COMPANY);
            
            for ($c = 0; $c < $categoryCount; $c++) {
                $baseSlug = Str::slug(fake()->words(2, true));
                $assetType = fake()->randomElement([AssetType::ASSET, AssetType::MARKETING]);
                $slug = $baseSlug;
                $counter = 1;
                
                // Ensure unique slug per tenant/brand/asset_type
                while (Category::where('tenant_id', $company->id)
                    ->where('brand_id', $brand->id)
                    ->where('asset_type', $assetType)
                    ->where('slug', $slug)
                    ->exists()) {
                    $slug = $baseSlug . '-' . $counter;
                    $counter++;
                }
                
                Category::create([
                    'tenant_id' => $company->id,
                    'brand_id' => $brand->id,
                    'name' => fake()->words(2, true),
                    'slug' => $slug,
                    'asset_type' => $assetType,
                    'is_system' => false,
                    'is_private' => fake()->boolean(20), // 20% private
                    'order' => $c,
                ]);
            }
        }
        
        // Create assets
        $assetCount = fake()->numberBetween(self::MIN_ASSETS_PER_COMPANY, self::MAX_ASSETS_PER_COMPANY);
        $categories = Category::where('tenant_id', $company->id)->get();
        $companyUsers = $company->users()->get();
        
        // Batch insert assets for performance
        $assetsToInsert = [];
        $batchSize = 100;
        
        for ($a = 0; $a < $assetCount; $a++) {
            $brand = fake()->randomElement($brands);
            $user = fake()->randomElement($companyUsers);
            $category = $categories->isNotEmpty() ? fake()->randomElement($categories) : null;
            
            $assetType = fake()->randomElement([AssetType::ASSET, AssetType::MARKETING, AssetType::AI_GENERATED]);
            $mimeTypes = [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'application/pdf', 'video/mp4', 'application/zip',
            ];
            $mimeType = fake()->randomElement($mimeTypes);
            $extension = $this->getExtensionFromMimeType($mimeType);
            $sizeBytes = fake()->numberBetween(10000, 50000000); // 10KB to 50MB
            
            // Create placeholder upload session (required for foreign key)
            $uploadSession = UploadSession::create([
                'tenant_id' => $company->id,
                'brand_id' => $brand->id,
                'storage_bucket_id' => $bucket->id,
                'status' => UploadStatus::COMPLETED,
                'type' => UploadType::DIRECT,
                'expected_size' => $sizeBytes,
                'uploaded_size' => $sizeBytes,
                'expires_at' => now()->addDays(30),
                'last_activity_at' => now(),
            ]);
            
            $assetsToInsert[] = [
                'id' => Str::uuid(),
                'tenant_id' => $company->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'upload_session_id' => $uploadSession->id,
                'storage_bucket_id' => $bucket->id,
                'status' => AssetStatus::VISIBLE,
                'type' => $assetType,
                'title' => fake()->sentence(3),
                'original_filename' => fake()->word() . '.' . $extension,
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
                'storage_root_path' => 'dev-seeder/assets/' . Str::uuid() . '.' . $extension, // Placeholder path
                'metadata' => json_encode([
                    'category_id' => $category ? $category->id : null,
                    'fields' => $this->generateRandomFields(),
                ]),
                'thumbnail_status' => fake()->randomElement([
                    ThumbnailStatus::PENDING,
                    ThumbnailStatus::PROCESSING,
                    ThumbnailStatus::COMPLETED,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            
            // Batch insert
            if (count($assetsToInsert) >= $batchSize) {
                Asset::insert($assetsToInsert);
                $assetsToInsert = [];
            }
        }
        
        // Insert remaining assets
        if (!empty($assetsToInsert)) {
            Asset::insert($assetsToInsert);
        }
    }
    
    /**
     * Create support tickets.
     */
    private function createSupportTickets(): void
    {
        $companies = Tenant::inRandomOrder()->limit(self::TICKETS_COUNT)->get();
        $users = User::inRandomOrder()->limit(self::TICKETS_COUNT * 2)->get();
        
        foreach ($companies as $index => $company) {
            if ($index >= self::TICKETS_COUNT) break;
            
            $createdBy = $users->random();
            $assignedTo = fake()->boolean(60) ? $users->random() : null;
            
            Ticket::create([
                'ticket_number' => 'SUP-' . str_pad($index + 1, 4, '0', STR_PAD_LEFT),
                'type' => fake()->randomElement(['tenant', 'tenant_internal', 'internal']),
                'status' => fake()->randomElement([
                    TicketStatus::OPEN,
                    TicketStatus::WAITING_ON_SUPPORT,
                    TicketStatus::IN_PROGRESS,
                    TicketStatus::RESOLVED,
                ]),
                'tenant_id' => $company->id,
                'created_by_user_id' => $createdBy->id,
                'assigned_to_user_id' => $assignedTo?->id,
                'assigned_team' => fake()->randomElement(['support', 'admin', 'engineering']),
                'metadata' => [
                    'subject' => fake()->sentence(),
                    'category' => fake()->word(),
                ],
            ]);
        }
    }
    
    /**
     * Get random plan based on distribution.
     */
    private function getRandomPlan(): string
    {
        $rand = fake()->randomFloat(2, 0, 1);
        $cumulative = 0;
        
        foreach (self::PLAN_DISTRIBUTION as $plan => $probability) {
            $cumulative += $probability;
            if ($rand <= $cumulative) {
                return $plan;
            }
        }
        
        return 'free';
    }
    
    /**
     * Get plan limits from config.
     */
    private function getPlanLimits(): array
    {
        $plans = config('plans', []);
        $limits = [];
        
        foreach ($plans as $planName => $planConfig) {
            $limits[$planName] = $planConfig['limits'] ?? [];
        }
        
        return $limits;
    }
    
    /**
     * Get file extension from MIME type.
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'video/mp4' => 'mp4',
            'application/zip' => 'zip',
            default => 'bin',
        };
    }
    
    /**
     * Generate random metadata fields.
     */
    private function generateRandomFields(): array
    {
        $fields = [];
        $fieldCount = fake()->numberBetween(0, 5);
        
        for ($i = 0; $i < $fieldCount; $i++) {
            $fieldName = fake()->word();
            $fields[$fieldName] = fake()->randomElement([
                fake()->word(),
                fake()->sentence(),
                fake()->numberBetween(1, 100),
                fake()->boolean(),
            ]);
        }
        
        return $fields;
    }
}
