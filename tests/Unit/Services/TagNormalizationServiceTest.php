<?php

namespace Tests\Unit\Services;

use App\Models\Tenant;
use App\Services\TagNormalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tag Normalization Service Test
 *
 * Phase J.2.1: Comprehensive testing of deterministic tag normalization
 * ensuring all tags resolve to canonical forms with proper synonym resolution,
 * block lists, and duplicate prevention.
 */
class TagNormalizationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TagNormalizationService $service;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TagNormalizationService();
        
        // Create test tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);
    }

    /**
     * Test: Basic normalization (lowercase, trim, punctuation removal)
     */
    public function test_basic_normalization(): void
    {
        $tests = [
            'Hi-Res' => 'hi-res',
            'HIGH RESOLUTION' => 'high-resolution', 
            ' hi res ' => 'hi-res',
            'Hi,Res!' => 'hires',
            'Hi!!Res' => 'hires',
            'hi---res' => 'hi-res',
            '-hi-res-' => 'hi-res',
        ];

        foreach ($tests as $input => $expected) {
            $result = $this->service->normalize($input, $this->tenant);
            $this->assertEquals($expected, $result, "Input: '$input' should normalize to '$expected'");
        }
    }

    /**
     * Test: Singularization rules
     */
    public function test_singularization(): void
    {
        $tests = [
            'categories' => 'category',
            'wolves' => 'wolf',  
            'classes' => 'class',
            'boxes' => 'box',
            'matches' => 'match',
            'wishes' => 'wish',
            'women' => 'woman',
            'dogs' => 'dog',
            'cats' => 'cat',
            // No change cases
            'thirteen' => 'thirteen',
            'cat' => 'cat', // Already singular
            'mouse' => 'mouse', // Irregular not handled
        ];

        foreach ($tests as $input => $expected) {
            $result = $this->service->normalize($input, $this->tenant);
            $this->assertEquals($expected, $result, "Input: '$input' should singularize to '$expected'");
        }
    }

    /**
     * Test: Length enforcement and truncation
     */
    public function test_length_enforcement(): void
    {
        // Test max length enforcement (64 chars)
        $longTag = str_repeat('a', 70);
        $result = $this->service->normalize($longTag, $this->tenant);
        $this->assertLessThanOrEqual(TagNormalizationService::MAX_TAG_LENGTH, strlen($result));

        // Test truncation doesn't leave partial words
        $longTag = 'this-is-a-very-long-tag-name-that-exceeds-the-maximum-length-and-should-be-truncated';
        $result = $this->service->normalize($longTag, $this->tenant);
        $this->assertLessThanOrEqual(TagNormalizationService::MAX_TAG_LENGTH, strlen($result));
        $this->assertFalse(str_ends_with($result, '-')); // No trailing hyphens
    }

    /**
     * Test: Invalid tag rejection
     */
    public function test_invalid_tag_rejection(): void
    {
        $invalidTags = [
            '', // Empty
            '   ', // Only whitespace
            '--', // Only hyphens
            'a', // Too short (< 2 chars)
            '!!', // Only punctuation
        ];

        foreach ($invalidTags as $invalid) {
            $result = $this->service->normalize($invalid, $this->tenant);
            $this->assertNull($result, "Invalid tag '$invalid' should be rejected");
        }
    }

    /**
     * Test: Valid edge cases
     */
    public function test_valid_edge_cases(): void
    {
        $validTags = [
            '4k' => '4k', // Numbers are valid
            'ab' => 'ab', // Minimum length
            'hi-res' => 'hi-res', // Hyphens in middle are preserved
            'mp3' => 'mp3', // Alphanumeric
        ];

        foreach ($validTags as $input => $expected) {
            $result = $this->service->normalize($input, $this->tenant);
            $this->assertEquals($expected, $result, "Valid tag '$input' should normalize to '$expected'");
        }
    }

    /**
     * Test: Synonym resolution (tenant-scoped)
     */
    public function test_synonym_resolution(): void
    {
        // Create synonyms
        DB::table('tag_synonyms')->insert([
            [
                'tenant_id' => $this->tenant->id,
                'synonym_tag' => 'high-resolution',
                'canonical_tag' => 'hi-res',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->tenant->id,
                'synonym_tag' => 'hi-res',
                'canonical_tag' => 'hires', // Further resolution
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Clear cache to ensure fresh data
        $this->service->clearCache($this->tenant);

        // Test synonym resolution
        $result1 = $this->service->normalize('HIGH RESOLUTION', $this->tenant);
        $this->assertEquals('hi-res', $result1); // Normalizes to 'high-resolution', then resolves to 'hi-res'
        
        $result2 = $this->service->normalize('hi res', $this->tenant);  
        $this->assertEquals('hires', $result2); // Normalizes to 'hi-res', then resolves to 'hires'
    }

    /**
     * Test: Block list enforcement (tenant-scoped)
     */
    public function test_block_list_enforcement(): void
    {
        // Create blocked tags
        DB::table('tag_rules')->insert([
            [
                'tenant_id' => $this->tenant->id,
                'tag' => 'spam',
                'rule_type' => 'block',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->tenant->id,
                'tag' => 'test',
                'rule_type' => 'block',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Clear cache to ensure fresh data
        $this->service->clearCache($this->tenant);

        // Test blocked tags are rejected
        $this->assertNull($this->service->normalize('SPAM', $this->tenant));
        $this->assertNull($this->service->normalize('test', $this->tenant));
        
        // Test non-blocked tags work
        $this->assertEquals('allowed', $this->service->normalize('allowed', $this->tenant));
    }

    /**
     * Test: Preferred tags identification
     */
    public function test_preferred_tags(): void
    {
        // Create preferred tags
        DB::table('tag_rules')->insert([
            [
                'tenant_id' => $this->tenant->id,
                'tag' => 'premium',
                'rule_type' => 'preferred',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Clear cache to ensure fresh data
        $this->service->clearCache($this->tenant);

        // Test preferred tag identification
        $this->assertTrue($this->service->isPreferred('premium', $this->tenant));
        $this->assertFalse($this->service->isPreferred('regular', $this->tenant));
    }

    /**
     * Test: Tenant isolation
     */
    public function test_tenant_isolation(): void
    {
        // Create another tenant
        $tenant2 = Tenant::create([
            'name' => 'Tenant 2',
            'slug' => 'tenant-2',
        ]);

        // Create synonyms for tenant 1
        DB::table('tag_synonyms')->insert([
            'tenant_id' => $this->tenant->id,
            'synonym_tag' => 'alias',
            'canonical_tag' => 'canonical',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create block list for tenant 2
        DB::table('tag_rules')->insert([
            'tenant_id' => $tenant2->id,
            'tag' => 'blocked',
            'rule_type' => 'block',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Clear caches
        $this->service->clearCache($this->tenant);
        $this->service->clearCache($tenant2);

        // Test tenant 1 doesn't see tenant 2's rules
        $this->assertEquals('blocked', $this->service->normalize('blocked', $this->tenant));
        $this->assertNull($this->service->normalize('blocked', $tenant2));

        // Test tenant 2 doesn't see tenant 1's synonyms
        $this->assertEquals('canonical', $this->service->normalize('alias', $this->tenant));
        $this->assertEquals('alias', $this->service->normalize('alias', $tenant2)); // No synonym resolution
    }

    /**
     * Test: Batch normalization
     */
    public function test_batch_normalization(): void
    {
        // Setup test data
        DB::table('tag_rules')->insert([
            'tenant_id' => $this->tenant->id,
            'tag' => 'blocked',
            'rule_type' => 'block',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service->clearCache($this->tenant);

        $inputTags = [
            'Valid Tag',
            'hi-res',
            'HI-RES', // Duplicate after normalization
            'blocked', // Blocked
            '', // Invalid
            'another-tag',
        ];

        $result = $this->service->normalizeBatch($inputTags, $this->tenant);

        $this->assertEquals(['valid-tag', 'hi-res', 'another-tag'], $result['canonical']);
        $this->assertEquals(['blocked'], $result['blocked']);
        $this->assertEquals([''], $result['invalid']);
    }

    /**
     * Test: Multiple tag normalization with deduplication
     */
    public function test_normalize_multiple(): void
    {
        $inputTags = [
            'Hi-Res',
            'hi res',
            'HIGH-RES', // All normalize to 'hi-res'
            'different',
            'Different', // Normalizes to 'different' - duplicate
        ];

        $result = $this->service->normalizeMultiple($inputTags, $this->tenant);
        
        $this->assertEquals(['hi-res', 'different'], $result);
        $this->assertCount(2, $result); // Duplicates removed
    }

    /**
     * Test: Equivalence checking
     */
    public function test_equivalence_checking(): void
    {
        // Test equivalent tags
        $this->assertTrue($this->service->areEquivalent('Hi-Res', 'hi res', $this->tenant));
        $this->assertTrue($this->service->areEquivalent('PHOTOS', 'photos', $this->tenant));
        
        // Test non-equivalent tags
        $this->assertFalse($this->service->areEquivalent('photo', 'video', $this->tenant));
        
        // Test with blocked tags (both normalize to null)
        DB::table('tag_rules')->insert([
            'tenant_id' => $this->tenant->id,
            'tag' => 'blocked',
            'rule_type' => 'block',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->service->clearCache($this->tenant);
        
        $this->assertFalse($this->service->areEquivalent('blocked', 'blocked', $this->tenant)); // Both null
    }

    /**
     * Test: Deterministic behavior (same input = same output)
     */
    public function test_deterministic_behavior(): void
    {
        $testCases = [
            'Hi-Res Photos!',
            'CATEGORIES',
            'test 123',
            ' mixed  CASE with   spaces ',
        ];

        foreach ($testCases as $input) {
            $result1 = $this->service->normalize($input, $this->tenant);
            $result2 = $this->service->normalize($input, $this->tenant);
            
            $this->assertEquals($result1, $result2, "Normalization should be deterministic for input: '$input'");
        }
    }

    /**
     * Test: Unicode handling
     */
    public function test_unicode_handling(): void
    {
        $tests = [
            'café' => 'café', // Preserve unicode letters
            'naïve' => 'naïve',
            'résumé' => 'résumé', 
        ];

        foreach ($tests as $input => $expected) {
            $result = $this->service->normalize($input, $this->tenant);
            $this->assertEquals($expected, $result, "Unicode input '$input' should normalize to '$expected'");
        }
    }

    /**
     * Test: Cache functionality
     */
    public function test_cache_functionality(): void
    {
        // Create test data
        DB::table('tag_synonyms')->insert([
            'tenant_id' => $this->tenant->id,
            'synonym_tag' => 'test',
            'canonical_tag' => 'canonical',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Clear cache to start fresh
        $this->service->clearCache($this->tenant);

        // First call should cache the data
        $result1 = $this->service->normalize('test', $this->tenant);
        $this->assertEquals('canonical', $result1);

        // Verify cache was used by checking cache directly
        $cacheKey = "tag_synonyms:{$this->tenant->id}";
        $cachedSynonyms = Cache::get($cacheKey);
        $this->assertNotNull($cachedSynonyms);
        $this->assertArrayHasKey('test', $cachedSynonyms);

        // Second call should use cache (same result)
        $result2 = $this->service->normalize('test', $this->tenant);
        $this->assertEquals($result1, $result2);

        // Clear cache and verify it's cleared
        $this->service->clearCache($this->tenant);
        $this->assertNull(Cache::get($cacheKey));
    }

    /**
     * Test: Complex normalization chain
     */
    public function test_complex_normalization_chain(): void
    {
        // Setup complex scenario: normalization + synonym + block check
        DB::table('tag_synonyms')->insert([
            'tenant_id' => $this->tenant->id,
            'synonym_tag' => 'high-resolution',
            'canonical_tag' => 'blocked-synonym',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tag_rules')->insert([
            'tenant_id' => $this->tenant->id,
            'tag' => 'blocked-synonym',
            'rule_type' => 'block',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service->clearCache($this->tenant);

        // Input normalizes to 'high-resolution', resolves to 'blocked-synonym', then gets blocked
        $result = $this->service->normalize('HIGH RESOLUTION!', $this->tenant);
        $this->assertNull($result, 'Tag should be blocked even after synonym resolution');
    }
}