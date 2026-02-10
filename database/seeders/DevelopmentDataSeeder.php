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
use App\Models\SystemCategory;
use App\Services\MetadataPersistenceService;
use App\Services\SystemCategoryService;
use App\Services\TenantBucketService;
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
 * Size Options (set via SEEDER_SIZE environment variable):
 *   - small:  ~20 companies, ~100 users, 10-50 assets per company
 *   - medium: ~100 companies, ~500 users, 50-500 assets per company
 *   - large:  ~1000 companies, ~10k users, 100-1000 assets per company (default)
 * 
 * Example:
 *   SEEDER_SIZE=small php artisan db:seed --class=DevelopmentDataSeeder --force
 * 
 * This seeder:
 * - Creates companies with dummy data (count depends on size)
 * - Each company has an owner (user with 'owner' role)
 * - Users are associated with companies and brands
 * - Assigns random plans (some with plans, some without)
 * - Generates users based on plan limits (some companies exceed limits)
 * - Creates categories and assets (counts depend on size)
 * - Fills in brand data (colors, logos, icons)
 * - Creates support tickets (count depends on size)
 * - Assigns special roles to some users (support, site admin, engineering)
 * 
 * S3 Images:
 * - Uses placeholder paths (no actual S3 uploads)
 * - Paths like: dev-seeder/placeholder.jpg
 * - Prevents gigabytes of S3 storage usage
 */
class DevelopmentDataSeeder extends Seeder
{
    // Size options: 'small', 'medium', 'large'
    // Can be overridden via SEEDER_SIZE environment variable
    private const SIZE = 'large'; // Default to large
    
    // Size configurations
    private const SIZE_CONFIG = [
        'small' => [
            'companies' => 20,
            'tickets' => 5,
            'min_assets' => 10,
            'max_assets' => 50,
            'min_categories' => 3,
            'max_categories' => 10,
            'min_brands' => 1,
            'max_brands' => 3,
        ],
        'medium' => [
            'companies' => 100,
            'tickets' => 10,
            'min_assets' => 50,
            'max_assets' => 500,
            'min_categories' => 5,
            'max_categories' => 15,
            'min_brands' => 1,
            'max_brands' => 4,
        ],
        'large' => [
            'companies' => 1000,
            'tickets' => 20,
            'min_assets' => 100,
            'max_assets' => 1000,
            'min_categories' => 5,
            'max_categories' => 20,
            'min_brands' => 1,
            'max_brands' => 5,
        ],
    ];
    
    private function getSize(): string
    {
        return env('SEEDER_SIZE', self::SIZE);
    }
    
    private function getConfig(): array
    {
        $size = $this->getSize();
        if (!isset(self::SIZE_CONFIG[$size])) {
            $this->command->warn("Invalid size '{$size}', defaulting to 'large'");
            $size = 'large';
        }
        return self::SIZE_CONFIG[$size];
    }
    
    private function getCompaniesCount(): int
    {
        return $this->getConfig()['companies'];
    }
    
    private function getTicketsCount(): int
    {
        return $this->getConfig()['tickets'];
    }
    
    private function getMinAssetsPerCompany(): int
    {
        return $this->getConfig()['min_assets'];
    }
    
    private function getMaxAssetsPerCompany(): int
    {
        return $this->getConfig()['max_assets'];
    }
    
    private function getMinCategoriesPerCompany(): int
    {
        return $this->getConfig()['min_categories'];
    }
    
    private function getMaxCategoriesPerCompany(): int
    {
        return $this->getConfig()['max_categories'];
    }
    
    private function getMinBrandsPerCompany(): int
    {
        return $this->getConfig()['min_brands'];
    }
    
    private function getMaxBrandsPerCompany(): int
    {
        return $this->getConfig()['max_brands'];
    }
    
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
        // Safety check - only run in local/testing; never staging or production
        $env = app()->environment();
        if (! in_array($env, ['local', 'testing'], true)) {
            $this->command->error('⚠️  This seeder is for local/testing only.');
            $this->command->error("Current environment: {$env}");
            $this->command->error('Aborting to prevent accidental staging/production data.');
            return;
        }
        
        // Get size configuration
        $size = $this->getSize();
        $companiesCount = $this->getCompaniesCount();
        $expectedUsers = $this->estimateUserCount($companiesCount);
        
        $this->command->info("📊 Seeder Size: " . strtoupper($size));
        $this->command->info("   Companies: ~{$companiesCount}");
        $this->command->info("   Users: ~{$expectedUsers}");
        $this->command->info("   Assets: " . $this->getMinAssetsPerCompany() . "-" . $this->getMaxAssetsPerCompany() . " per company");
        
        // Confirmation prompt (skip if --force is used or in non-interactive mode)
        $isForced = $this->command->option('force') ?? false;
        if (!$isForced && !$this->command->confirm("This will generate {$companiesCount} companies with extensive test data. Continue?", false)) {
            $this->command->info('Seeder cancelled.');
            return;
        }
        
        $this->command->info('🚀 Starting development data generation...');
        $startTime = microtime(true);
        
        // Get plan limits from config
        $planLimits = $this->getPlanLimits();
        
        // Create companies in chunks to manage memory
        $chunkSize = $size === 'small' ? 10 : ($size === 'medium' ? 25 : 50);
        $totalChunks = ceil($companiesCount / $chunkSize);
        
        for ($chunk = 0; $chunk < $totalChunks; $chunk++) {
            $offset = $chunk * $chunkSize;
            $count = min($chunkSize, $companiesCount - $offset);
            
            $this->command->info("Creating companies " . ($offset + 1) . "-" . ($offset + $count) . " of " . $companiesCount . "...");
            
            for ($i = 0; $i < $count; $i++) {
                $this->createCompanyWithData($planLimits);
            }
            
            // Clear memory
            if ($chunk % 10 === 0) {
                gc_collect_cycles();
            }
        }
        
        // Seed assets for company 1 / brand 1 (default seed company) with lots of filterable metadata
        $this->command->info('Seeding assets for company 1 / brand 1 (lots of filters)...');
        $this->seedAssetsForFirstCompanyBrand($planLimits);

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
        
        // Create storage bucket with canonical expected name (so resolveActiveBucketOrFail finds it)
        $expectedBucketName = app(TenantBucketService::class)->getBucketName($company);
        $bucket = StorageBucket::create([
            'tenant_id' => $company->id,
            'name' => $expectedBucketName,
            'status' => StorageBucketStatus::ACTIVE,
            'region' => config('storage.default_region', 'us-east-1'),
        ]);
        
        // Create brands
        $brandCount = fake()->numberBetween($this->getMinBrandsPerCompany(), $this->getMaxBrandsPerCompany());
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

        // Sync system categories (Photography, Logos, etc.) so filters and type fields work
        $systemCategoryService = app(SystemCategoryService::class);
        foreach ($brands as $brand) {
            $systemCategoryService->syncToBrand($brand);
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
        
        // Attach owner to default brand as admin (owner is tenant-level only)
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
            
            // Assign role using new role system
            // Distribution: 30% viewer, 25% uploader, 25% contributor, 10% manager, 10% admin
            $role = fake()->randomElement([
                'viewer', 'viewer', 'viewer', // 30%
                'uploader', 'uploader', 'uploader', // 30%
                'contributor', 'contributor', 'contributor', // 30%
                'manager', // 5%
                'admin', // 5%
            ]);
            $company->users()->attach($user->id, ['role' => $role]);
            
            // Assign to random brands with appropriate roles
            $brandsToAssign = fake()->randomElements($brands, fake()->numberBetween(1, min(count($brands), 3)));
            foreach ($brandsToAssign as $brand) {
                // Brand roles: viewer, uploader, contributor, manager, admin (no owner at brand level)
                $brandRole = fake()->randomElement(['viewer', 'uploader', 'contributor', 'manager', 'admin']);
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
            $categoryCount = fake()->numberBetween($this->getMinCategoriesPerCompany(), $this->getMaxCategoriesPerCompany());
            
            for ($c = 0; $c < $categoryCount; $c++) {
                $baseSlug = Str::slug(fake()->words(2, true));
                $assetType = fake()->randomElement([AssetType::ASSET, AssetType::DELIVERABLE]);
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
        
        // Create assets with proper metadata fields (Assets + Executions/deliverables)
        $assetCount = fake()->numberBetween($this->getMinAssetsPerCompany(), $this->getMaxAssetsPerCompany());
        $allCategories = Category::where('tenant_id', $company->id)->get();
        $assetCategories = $allCategories->filter(fn ($c) => $c->asset_type === AssetType::ASSET)->values();
        $deliverableCategories = $allCategories->filter(fn ($c) => $c->asset_type === AssetType::DELIVERABLE)->values();
        $companyUsers = $company->users()->get();
        $metadataFields = $this->getMetadataFieldsForSeeding();

        for ($a = 0; $a < $assetCount; $a++) {
            $brand = fake()->randomElement($brands);
            $user = fake()->randomElement($companyUsers);
            // ~25% deliverables (Executions), rest assets; pick category matching type
            $assetType = fake()->randomElement([AssetType::ASSET, AssetType::ASSET, AssetType::ASSET, AssetType::DELIVERABLE, AssetType::AI_GENERATED]);
            if ($assetType === AssetType::DELIVERABLE && $deliverableCategories->isEmpty()) {
                $assetType = AssetType::ASSET;
            }
            $categoriesForType = ($assetType === AssetType::DELIVERABLE) ? $deliverableCategories : $assetCategories;
            $category = $categoriesForType->isNotEmpty() ? $categoriesForType->random() : ($allCategories->isNotEmpty() ? $allCategories->random() : null);
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
            
            // Generate realistic metadata fields (include type field for system categories)
            $metadataFieldsData = $this->generateRealisticMetadataFields($metadataFields, $category);
            
            // Most assets are visible and published (public); ~15% stay unpublished for lifecycle testing
            $isPublished = fake()->boolean(85);
            $asset = Asset::create([
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
                'metadata' => [
                    'category_id' => $category ? $category->id : null,
                    'fields' => $metadataFieldsData,
                ],
                'thumbnail_status' => fake()->randomElement([
                    ThumbnailStatus::PENDING,
                    ThumbnailStatus::PROCESSING,
                    ThumbnailStatus::COMPLETED,
                ]),
                'published_at' => $isPublished ? now() : null,
                'published_by_id' => $isPublished ? $user->id : null,
            ]);
            
            // Persist metadata fields to asset_metadata table if category and fields exist
            if ($category && !empty($metadataFieldsData)) {
                try {
                    $persistenceService = app(MetadataPersistenceService::class);
                    $persistenceService->persistMetadata(
                        $asset,
                        $category,
                        $metadataFieldsData,
                        $user->id,
                        'image', // Default to image for metadata schema resolution
                        true // Auto-approve seeder metadata
                    );
                } catch (\Exception $e) {
                    // Log but don't fail - metadata in JSON is still valid
                    \Log::warning('[DevelopmentDataSeeder] Failed to persist metadata', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
    
    /**
     * Number of assets to create for company 1 / brand 1 (lots of filter data).
     */
    private const FIRST_COMPANY_ASSETS = [
        'small' => 80,
        'medium' => 150,
        'large' => 250,
    ];

    /** Number of execution/deliverable assets for company 1 (Executions tab with metadata). */
    private const FIRST_COMPANY_DELIVERABLES = [
        'small' => 25,
        'medium' => 45,
        'large' => 80,
    ];

    /**
     * Seed assets for the first company and its default brand (company 1 / brand 1 from default seed).
     * Ensures the primary dev tenant has plenty of assets with rich filter metadata.
     */
    private function seedAssetsForFirstCompanyBrand(array $planLimits): void
    {
        $company = Tenant::orderBy('id')->first();
        if (!$company) {
            return;
        }
        $brand = $company->defaultBrand ?? $company->brands()->where('is_default', true)->first() ?? $company->brands()->first();
        if (!$brand) {
            return;
        }
        $bucket = StorageBucket::firstOrCreate(
            [
                'tenant_id' => $company->id,
                'name' => app(TenantBucketService::class)->getBucketName($company),
            ],
            [
                'status' => StorageBucketStatus::ACTIVE,
                'region' => config('storage.default_region', 'us-east-1'),
            ]
        );
        $categories = Category::where('tenant_id', $company->id)->where('brand_id', $brand->id)->get();
        if ($categories->isEmpty()) {
            app(SystemCategoryService::class)->syncToBrand($brand);
            $categories = Category::where('tenant_id', $company->id)->where('brand_id', $brand->id)->get();
        }
        $companyUsers = $company->users()->get();
        if ($companyUsers->isEmpty()) {
            return;
        }
        $metadataFields = $this->getMetadataFieldsForSeeding();
        $assetCount = self::FIRST_COMPANY_ASSETS[$this->getSize()] ?? self::FIRST_COMPANY_ASSETS['small'];
        $deliverableCount = self::FIRST_COMPANY_DELIVERABLES[$this->getSize()] ?? self::FIRST_COMPANY_DELIVERABLES['small'];
        $assetCategories = $categories->filter(fn ($c) => $c->asset_type === AssetType::ASSET)->values();
        $deliverableCategories = $categories->filter(fn ($c) => $c->asset_type === AssetType::DELIVERABLE)->values();
        $systemAssetCategories = $assetCategories->filter(fn ($c) => $c->system_category_id !== null)->values();
        $systemDeliverableCategories = $deliverableCategories->filter(fn ($c) => $c->system_category_id !== null)->values();

        // 1) Create execution/deliverable assets (Executions tab) with metadata
        $deliverablesToCreate = $deliverableCategories->isNotEmpty() ? $deliverableCount : 0;
        for ($d = 0; $d < $deliverablesToCreate; $d++) {
            $user = $companyUsers->random();
            $category = $systemDeliverableCategories->isNotEmpty() && fake()->boolean(85)
                ? $systemDeliverableCategories->random()
                : $deliverableCategories->random();
            $this->createSeededAsset(
                $company,
                $brand,
                $bucket,
                $user,
                $category,
                AssetType::DELIVERABLE,
                $metadataFields,
                true
            );
        }

        // 2) Create remaining assets (Assets tab) with metadata
        $remaining = $assetCount - $deliverablesToCreate;
        $preferSystem = $systemAssetCategories->isNotEmpty();
        for ($a = 0; $a < $remaining; $a++) {
            $user = $companyUsers->random();
            $category = $assetCategories->isEmpty() ? $categories->random() : (
                $preferSystem && fake()->boolean(80) ? $systemAssetCategories->random() : $assetCategories->random()
            );
            $assetType = fake()->randomElement([AssetType::ASSET, AssetType::ASSET, AssetType::AI_GENERATED]);
            $this->createSeededAsset(
                $company,
                $brand,
                $bucket,
                $user,
                $category,
                $assetType,
                $metadataFields,
                true
            );
        }
    }

    /**
     * Create a single asset with metadata (shared by company loop and first-company seed).
     */
    private function createSeededAsset(
        Tenant $company,
        Brand $brand,
        StorageBucket $bucket,
        User $user,
        Category $category,
        AssetType $assetType,
        $metadataFields,
        bool $highFillRate = false
    ): void {
        $mimeTypes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf', 'video/mp4', 'application/zip',
        ];
        $mimeType = fake()->randomElement($mimeTypes);
        $extension = $this->getExtensionFromMimeType($mimeType);
        $sizeBytes = fake()->numberBetween(10000, 50000000);
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
        $metadataFieldsData = $this->generateRealisticMetadataFields($metadataFields, $category, $highFillRate);
        $isPublished = $highFillRate ? fake()->boolean(90) : fake()->boolean(85);
        $asset = Asset::create([
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
            'storage_root_path' => 'dev-seeder/assets/' . Str::uuid() . '.' . $extension,
            'metadata' => [
                'category_id' => $category->id,
                'fields' => $metadataFieldsData,
            ],
            'thumbnail_status' => fake()->randomElement([
                ThumbnailStatus::PENDING,
                ThumbnailStatus::PROCESSING,
                ThumbnailStatus::COMPLETED,
            ]),
            'published_at' => $isPublished ? now() : null,
            'published_by_id' => $isPublished ? $user->id : null,
        ]);
        if (!empty($metadataFieldsData)) {
            try {
                app(MetadataPersistenceService::class)->persistMetadata(
                    $asset,
                    $category,
                    $metadataFieldsData,
                    $user->id,
                    'image',
                    true
                );
            } catch (\Exception $e) {
                \Log::warning('[DevelopmentDataSeeder] Persist metadata failed', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Estimate user count based on company count and plan distribution.
     */
    private function estimateUserCount(int $companiesCount): int
    {
        // Rough estimate: average 5 users per company for small, 5-10 for medium, 5-20 for large
        $size = $this->getSize();
        $avgUsersPerCompany = match($size) {
            'small' => 5,
            'medium' => 7,
            'large' => 10,
            default => 10,
        };
        return (int) ($companiesCount * $avgUsersPerCompany);
    }
    
    /**
     * Create support tickets.
     */
    private function createSupportTickets(): void
    {
        $ticketsCount = $this->getTicketsCount();
        $companies = Tenant::inRandomOrder()->limit($ticketsCount)->get();
        $users = User::inRandomOrder()->limit($ticketsCount * 2)->get();
        
        $prefix = 'SUP-DEV-' . Str::random(6) . '-';
        foreach ($companies as $index => $company) {
            if ($index >= $ticketsCount) break;
            
            $createdBy = $users->random();
            $assignedTo = fake()->boolean(60) ? $users->random() : null;
            
            Ticket::withoutEvents(function () use ($prefix, $index, $company, $createdBy, $assignedTo) {
                Ticket::create([
                'ticket_number' => $prefix . str_pad($index + 1, 4, '0', STR_PAD_LEFT),
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
            });
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
     * Get metadata field definitions for seeding. Includes any field that is user-editable and
     * visible on upload OR filterable (so type fields and common fields are always included).
     */
    private function getMetadataFieldsForSeeding(): \Illuminate\Support\Collection
    {
        $query = DB::table('metadata_fields')
            ->where('is_user_editable', true)
            ->where('is_internal_only', false)
            ->whereNull('deprecated_at')
            ->where(function ($q) {
                $q->where('is_upload_visible', true)
                    ->orWhere('is_filterable', true);
            })
            ->orderBy('id')
            ->get(['id', 'key', 'type']);
        return $query->isEmpty()
            ? DB::table('metadata_fields')->whereNull('deprecated_at')->get(['id', 'key', 'type'])
            : $query;
    }

    /**
     * System category slug -> type field key (for filterable type fields).
     */
    private const SYSTEM_SLUG_TO_TYPE_FIELD = [
        'photography' => 'photo_type',
        'logos' => 'logo_type',
        'graphics' => 'graphic_type',
        'video' => 'video_type',
        'templates' => 'template_type',
        'audio' => 'audio_type',
        'model-3d' => 'model_3d_type',
        'print' => 'print_type',
        'digital-ads' => 'digital_type',
        'ooh' => 'ooh_type',
        'events' => 'event_type',
        'videos' => 'execution_video_type',
        'sales-collateral' => 'sales_collateral_type',
        'pr' => 'pr_type',
        'packaging' => 'packaging_type',
        'product-renders' => 'product_render_type',
        'radio' => 'radio_type',
    ];

    /**
     * Generate realistic metadata fields based on actual metadata field definitions.
     * When category is system-linked, sets the corresponding type field so filters return results.
     *
     * @param  bool  $highFillRate  When true, populate ~90% of fields (for "lots of filter" data)
     */
    private function generateRealisticMetadataFields($metadataFields, ?Category $category = null, bool $highFillRate = false): array
    {
        $fields = [];

        // If asset is in a system category, set the type field for that category (so filters work)
        if ($category?->system_category_id) {
            $systemCategory = SystemCategory::find($category->system_category_id);
            if ($systemCategory && isset(self::SYSTEM_SLUG_TO_TYPE_FIELD[$systemCategory->slug])) {
                $typeKey = self::SYSTEM_SLUG_TO_TYPE_FIELD[$systemCategory->slug];
                $value = $this->getSelectFieldValue($typeKey);
                if ($value !== null) {
                    $fields[$typeKey] = $value;
                }
            }
        }

        $fillChance = $highFillRate ? 90 : 50;
        $minFields = ($highFillRate || $category) ? 4 : 1;
        $remaining = $metadataFields->filter(fn ($f) => !isset($fields[$f->key]))->values();
        $added = 0;
        foreach ($remaining as $field) {
            $needMore = count($fields) < $minFields;
            $roll = fake()->boolean($fillChance);
            if (!$needMore && !$roll) {
                continue;
            }
            if ($needMore && !$roll) {
                $roll = true; // Force add to reach minimum
            }
            if (!$roll) {
                continue;
            }

            $value = match ($field->type) {
                'select' => $this->getSelectFieldValue($field->key),
                'multiselect' => $this->getMultiselectFieldValue($field->key),
                'text' => fake()->sentence(3),
                'date' => fake()->dateTimeBetween('-1 year', '+1 year')->format('Y-m-d'),
                'number' => fake()->numberBetween(1, 1000),
                'boolean' => fake()->boolean(),
                default => fake()->word(),
            };

            if ($value !== null) {
                $fields[$field->key] = $value;
                $added++;
            }
        }

        return $fields;
    }
    
    /**
     * Get a realistic value for a select field. Uses seeded option values for type fields so filters work.
     */
    private function getSelectFieldValue(string $fieldKey): ?string
    {
        return match ($fieldKey) {
            // Asset type fields (one per system category)
            'photo_type' => fake()->randomElement(['studio', 'lifestyle']),
            'logo_type' => fake()->randomElement(['primary', 'secondary', 'promotional']),
            'graphic_type' => fake()->randomElement(['icon', 'effect', 'texture']),
            'video_type' => fake()->randomElement(['b_roll', 'interviews']),
            'template_type' => fake()->randomElement(['email', 'social']),
            'audio_type' => null, // No options in seeder
            'model_3d_type' => null,
            'product_render_type' => null,
            // Execution/deliverable type fields
            'print_type' => fake()->randomElement(['ads', 'brochures', 'posters', 'inserts']),
            'digital_type' => 'display_ads',
            'ooh_type' => fake()->randomElement(['billboards', 'signage']),
            'event_type' => fake()->randomElement(['booths', 'transit', 'experiential']),
            'execution_video_type' => fake()->randomElement(['broadcast', 'pre_roll', 'brand_video', 'explainer_video', 'product_demos']),
            'sales_collateral_type' => fake()->randomElement(['catalogs', 'sales_sheets', 'trade_show_materials']),
            'pr_type' => fake()->randomElement(['press_releases', 'media_kits', 'backgrounders']),
            'packaging_type' => fake()->randomElement(['flat_art', 'renders_3d']),
            'radio_type' => fake()->randomElement(['broadcast_spots', 'live_reads']),
            // Other common fields
            'usage_rights' => fake()->randomElement(['unrestricted', 'editorial_only', 'internal', 'external', 'social', 'print', 'web']),
            'orientation' => fake()->randomElement(['landscape', 'portrait', 'square']),
            'color_space' => fake()->randomElement(['srgb', 'adobe_rgb', 'cmyk']),
            'resolution_class' => fake()->randomElement(['low', 'medium', 'high', 'ultra']),
            'scene_classification' => fake()->randomElement(['indoor', 'outdoor', 'studio', 'natural']),
            default => fake()->word(),
        };
    }
    
    /**
     * Get a realistic value for a multiselect field.
     */
    private function getMultiselectFieldValue(string $fieldKey): ?array
    {
        return match ($fieldKey) {
            'ai_detected_objects' => fake()->randomElements(['person', 'car', 'building', 'animal', 'food', 'nature'], fake()->numberBetween(1, 3)),
            'ai_color_palette' => fake()->randomElements(['red', 'blue', 'green', 'yellow', 'orange', 'purple'], fake()->numberBetween(2, 4)),
            default => [fake()->word(), fake()->word()],
        };
    }
}
