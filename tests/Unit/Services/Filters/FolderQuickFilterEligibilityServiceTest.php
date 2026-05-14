<?php

namespace Tests\Unit\Services\Filters;

use App\Models\MetadataField;
use App\Services\Filters\FolderQuickFilterEligibilityService;
use Tests\TestCase;

class FolderQuickFilterEligibilityServiceTest extends TestCase
{
    private FolderQuickFilterEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FolderQuickFilterEligibilityService();
        // Ensure tests run against the documented Phase 1 defaults regardless of
        // any prior call leaving the in-memory config in an unexpected state.
        config([
            'categories.folder_quick_filters.enabled' => true,
            'categories.folder_quick_filters.allowed_types' => [
                'single_select',
                'multi_select',
                'boolean',
            ],
        ]);
    }

    /**
     * Build a minimal active, non-archived, filterable, non-internal row.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'type' => 'select',
            'is_active' => true,
            'is_filterable' => true,
            'is_internal_only' => false,
            'show_in_filters' => true,
            'archived_at' => null,
            'deprecated_at' => null,
            'scope' => 'system',
        ], $overrides);
    }

    public function test_single_select_is_eligible(): void
    {
        $this->assertTrue($this->service->isEligible($this->row(['type' => 'select'])));
        $this->assertNull($this->service->reasonIneligible($this->row(['type' => 'select'])));
    }

    public function test_multi_select_is_eligible(): void
    {
        $this->assertTrue($this->service->isEligible($this->row(['type' => 'multiselect'])));
        $this->assertTrue($this->service->isEligible($this->row(['type' => 'multi_select'])));
    }

    public function test_boolean_is_eligible(): void
    {
        $this->assertTrue($this->service->isEligible($this->row(['type' => 'boolean'])));
    }

    public function test_text_is_not_eligible(): void
    {
        $row = $this->row(['type' => 'text']);
        $this->assertFalse($this->service->isEligible($row));
        $this->assertSame(
            FolderQuickFilterEligibilityService::REASON_TYPE_NOT_ALLOWED,
            $this->service->reasonIneligible($row)
        );
    }

    public function test_date_and_date_range_are_not_eligible(): void
    {
        foreach (['date', 'date_range'] as $type) {
            $reason = $this->service->reasonIneligible($this->row(['type' => $type]));
            $this->assertSame(
                FolderQuickFilterEligibilityService::REASON_TYPE_NOT_ALLOWED,
                $reason,
                "type={$type} should be ineligible by type"
            );
        }
    }

    public function test_number_and_number_range_are_not_eligible(): void
    {
        foreach (['number', 'number_range'] as $type) {
            $this->assertFalse($this->service->isEligible($this->row(['type' => $type])));
        }
    }

    public function test_file_and_url_are_not_eligible(): void
    {
        foreach (['file', 'url'] as $type) {
            $this->assertFalse($this->service->isEligible($this->row(['type' => $type])));
        }
    }

    public function test_all_known_disallowed_types_are_rejected_with_type_reason(): void
    {
        foreach (FolderQuickFilterEligibilityService::knownDisallowedTypes() as $type) {
            $reason = $this->service->reasonIneligible($this->row(['type' => $type]));
            $this->assertSame(
                FolderQuickFilterEligibilityService::REASON_TYPE_NOT_ALLOWED,
                $reason,
                "Type '{$type}' should be ineligible by type"
            );
        }
    }

    public function test_archived_filter_is_not_eligible(): void
    {
        $row = $this->row(['archived_at' => '2026-01-01 00:00:00']);
        $this->assertSame(
            FolderQuickFilterEligibilityService::REASON_ARCHIVED,
            $this->service->reasonIneligible($row)
        );
    }

    public function test_disabled_filter_is_not_eligible(): void
    {
        $row = $this->row(['is_active' => false]);
        $this->assertSame(
            FolderQuickFilterEligibilityService::REASON_DISABLED,
            $this->service->reasonIneligible($row)
        );
    }

    public function test_internal_filter_is_not_eligible(): void
    {
        $row = $this->row(['is_internal_only' => true]);
        $this->assertSame(
            FolderQuickFilterEligibilityService::REASON_INTERNAL,
            $this->service->reasonIneligible($row)
        );
    }

    public function test_deprecated_filter_is_not_eligible(): void
    {
        $row = $this->row(['deprecated_at' => '2026-01-01 00:00:00']);
        $this->assertSame(
            FolderQuickFilterEligibilityService::REASON_DEPRECATED,
            $this->service->reasonIneligible($row)
        );
    }

    public function test_filter_hidden_from_filter_surfaces_is_not_eligible(): void
    {
        $row = $this->row([
            'is_filterable' => false,
            'show_in_filters' => false,
        ]);
        $this->assertSame(
            FolderQuickFilterEligibilityService::REASON_NOT_FILTERABLE,
            $this->service->reasonIneligible($row)
        );
    }

    public function test_disabling_feature_in_config_makes_everything_ineligible(): void
    {
        config(['categories.folder_quick_filters.enabled' => false]);

        foreach (['select', 'multiselect', 'boolean'] as $type) {
            $reason = $this->service->reasonIneligible($this->row(['type' => $type]));
            $this->assertSame(
                FolderQuickFilterEligibilityService::REASON_FEATURE_DISABLED,
                $reason,
                "type={$type} should be ineligible because feature is off"
            );
        }
    }

    public function test_narrowing_allowed_types_in_config_excludes_now_disallowed_types(): void
    {
        config(['categories.folder_quick_filters.allowed_types' => ['boolean']]);

        $this->assertFalse($this->service->isEligible($this->row(['type' => 'select'])));
        $this->assertFalse($this->service->isEligible($this->row(['type' => 'multiselect'])));
        $this->assertTrue($this->service->isEligible($this->row(['type' => 'boolean'])));
    }

    public function test_explain_reason_returns_admin_facing_text_for_each_reason(): void
    {
        $reasons = [
            FolderQuickFilterEligibilityService::REASON_FEATURE_DISABLED,
            FolderQuickFilterEligibilityService::REASON_TYPE_NOT_ALLOWED,
            FolderQuickFilterEligibilityService::REASON_DISABLED,
            FolderQuickFilterEligibilityService::REASON_ARCHIVED,
            FolderQuickFilterEligibilityService::REASON_DEPRECATED,
            FolderQuickFilterEligibilityService::REASON_INTERNAL,
            FolderQuickFilterEligibilityService::REASON_NOT_FILTERABLE,
            FolderQuickFilterEligibilityService::REASON_NO_TYPE,
            FolderQuickFilterEligibilityService::REASON_INVALID_INPUT,
        ];
        foreach ($reasons as $reason) {
            $msg = $this->service->explainReason($reason);
            $this->assertNotNull($msg, "Reason '{$reason}' must explain itself");
            $this->assertNotSame('', trim((string) $msg));
        }
    }

    public function test_explain_reason_for_type_mentions_select_and_boolean(): void
    {
        $msg = (string) $this->service->explainReason(
            FolderQuickFilterEligibilityService::REASON_TYPE_NOT_ALLOWED
        );
        $this->assertStringContainsStringIgnoringCase('select', $msg);
        $this->assertStringContainsStringIgnoringCase('boolean', $msg);
    }

    public function test_explain_reason_returns_null_for_null_reason(): void
    {
        $this->assertNull($this->service->explainReason(null));
    }

    public function test_canonical_type_maps_db_strings_and_aliases(): void
    {
        $this->assertSame('single_select', $this->service->canonicalType('select'));
        $this->assertSame('single_select', $this->service->canonicalType('single_select'));
        $this->assertSame('multi_select', $this->service->canonicalType('multiselect'));
        $this->assertSame('multi_select', $this->service->canonicalType('multi_select'));
        $this->assertSame('boolean', $this->service->canonicalType('boolean'));
        $this->assertNull($this->service->canonicalType('text'));
        $this->assertNull($this->service->canonicalType(null));
    }

    public function test_accepts_metadata_field_eloquent_instance(): void
    {
        $field = new MetadataField([
            'key' => 'photo_type',
            'system_label' => 'Photo type',
            'type' => 'select',
            'is_active' => true,
            'is_filterable' => true,
            'is_internal_only' => false,
            'show_in_filters' => true,
        ]);
        $this->assertTrue($this->service->isEligible($field));

        $field->is_internal_only = true;
        $this->assertSame(
            FolderQuickFilterEligibilityService::REASON_INTERNAL,
            $this->service->reasonIneligible($field)
        );
    }

    public function test_accepts_db_row_objects(): void
    {
        $row = (object) $this->row(['type' => 'select']);
        $this->assertTrue($this->service->isEligible($row));
    }

    public function test_invalid_inputs_are_reported_not_thrown(): void
    {
        $this->assertSame(
            FolderQuickFilterEligibilityService::REASON_INVALID_INPUT,
            $this->service->reasonIneligible(null)
        );
        $this->assertSame(
            FolderQuickFilterEligibilityService::REASON_INVALID_INPUT,
            $this->service->reasonIneligible('select')
        );
        $this->assertSame(
            FolderQuickFilterEligibilityService::REASON_INVALID_INPUT,
            $this->service->reasonIneligible(42)
        );
    }

    public function test_missing_type_is_reported(): void
    {
        $row = $this->row();
        unset($row['type']);
        $this->assertSame(
            FolderQuickFilterEligibilityService::REASON_NO_TYPE,
            $this->service->reasonIneligible($row)
        );
    }
}
