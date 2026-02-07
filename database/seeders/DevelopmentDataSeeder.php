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
use App\Services\MetadataPersistenceService;
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
        
        // Create assets with proper metadata fields
        $assetCount = fake()->numberBetween($this->getMinAssetsPerCompany(), $this->getMaxAssetsPerCompany());
        $categories = Category::where('tenant_id', $company->id)->get();
        $companyUsers = $company->users()->get();
        
        // Get metadata fields for generating realistic metadata
        $metadataFields = DB::table('metadata_fields')
            ->where('is_user_editable', true)
            ->where('show_on_upload', true)
            ->get(['id', 'key', 'type']);
        
        for ($a = 0; $a < $assetCount; $a++) {
            $brand = fake()->randomElement($brands);
            $user = fake()->randomElement($companyUsers);
            $category = $categories->isNotEmpty() ? fake()->randomElement($categories) : null;
            
            $assetType = fake()->randomElement([AssetType::ASSET, AssetType::DELIVERABLE, AssetType::AI_GENERATED]);
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
            
            // Generate realistic metadata fields
            $metadataFieldsData = $this->generateRealisticMetadataFields($metadataFields);
            
            // Create asset
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
        
        foreach ($companies as $index => $company) {
            if ($index >= $ticketsCount) break;
            
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
     * Generate realistic metadata fields based on actual metadata field definitions.
     */
    private function generateRealisticMetadataFields($metadataFields): array
    {
        $fields = [];
        
        // Only populate a subset of fields (30-70% chance per field)
        foreach ($metadataFields as $field) {
            if (!fake()->boolean(50)) {
                continue; // Skip 50% of fields randomly
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
            }
        }
        
        return $fields;
    }
    
    /**
     * Get a realistic value for a select field based on common field keys.
     */
    private function getSelectFieldValue(string $fieldKey): ?string
    {
        return match ($fieldKey) {
            'photo_type' => fake()->randomElement(['action', 'portrait', 'landscape', 'product', 'lifestyle', 'event']),
            'usage_rights' => fake()->randomElement(['internal', 'external', 'social', 'print', 'web']),
            'orientation' => fake()->randomElement(['landscape', 'portrait', 'square']),
            'color_space' => fake()->randomElement(['RGB', 'CMYK', 'sRGB']),
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
