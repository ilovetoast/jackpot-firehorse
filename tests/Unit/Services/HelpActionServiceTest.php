<?php

namespace Tests\Unit\Services;

use App\Models\Brand;
use App\Services\HelpActionService;
use Tests\TestCase;

class HelpActionServiceTest extends TestCase
{
    public function test_returns_common_actions_for_user_with_access(): void
    {
        config(['help_actions.actions' => [
            [
                'key' => 'a1',
                'title' => 'Alpha',
                'aliases' => [],
                'category' => 'Test',
                'short_answer' => 'Short',
                'steps' => ['One'],
                'route_name' => null,
                'route_bindings' => [],
                'page_label' => 'Alpha page',
                'permissions' => [],
                'tags' => [],
                'related' => [],
                'in_common' => true,
                'common_sort' => 2,
            ],
            [
                'key' => 'a2',
                'title' => 'Beta',
                'aliases' => [],
                'category' => 'Test',
                'short_answer' => 'Other',
                'steps' => [],
                'route_name' => null,
                'route_bindings' => [],
                'page_label' => 'Beta page',
                'permissions' => [],
                'tags' => [],
                'related' => [],
                'in_common' => false,
                'common_sort' => 1,
            ],
        ]]);

        $service = app(HelpActionService::class);
        $out = $service->forRequest(null, [], null);

        $this->assertNull($out['query']);
        $this->assertSame([], $out['results']);
        $this->assertCount(1, $out['common']);
        $this->assertSame('Alpha', $out['common'][0]['title']);
    }

    public function test_search_matches_aliases(): void
    {
        config(['help_actions.actions' => [
            [
                'key' => 'x',
                'title' => 'Obscure title',
                'aliases' => ['special-keyword'],
                'category' => 'Cat',
                'short_answer' => 'Body',
                'steps' => [],
                'route_name' => null,
                'route_bindings' => [],
                'page_label' => 'P',
                'permissions' => [],
                'tags' => [],
                'related' => [],
                'in_common' => false,
                'common_sort' => 0,
            ],
        ]]);

        $service = app(HelpActionService::class);
        $out = $service->forRequest('special-keyword', [], null);

        $this->assertSame('special-keyword', $out['query']);
        $this->assertCount(1, $out['results']);
        $this->assertSame('x', $out['results'][0]['key']);
    }

    public function test_search_matches_tags(): void
    {
        config(['help_actions.actions' => [
            [
                'key' => 'tagged',
                'title' => 'Thing',
                'aliases' => [],
                'category' => 'Cat',
                'short_answer' => 'S',
                'steps' => [],
                'route_name' => null,
                'route_bindings' => [],
                'page_label' => 'P',
                'permissions' => [],
                'tags' => ['unicorn-label'],
                'related' => [],
                'in_common' => false,
                'common_sort' => 0,
            ],
        ]]);

        $service = app(HelpActionService::class);
        $out = $service->forRequest('unicorn', [], null);

        $this->assertCount(1, $out['results']);
        $this->assertSame('tagged', $out['results'][0]['key']);
    }

    public function test_hides_actions_when_user_lacks_required_permission(): void
    {
        config(['help_actions.actions' => [
            [
                'key' => 'secret',
                'title' => 'Billing thing',
                'aliases' => [],
                'category' => 'C',
                'short_answer' => 'S',
                'steps' => [],
                'route_name' => null,
                'route_bindings' => [],
                'page_label' => 'P',
                'permissions' => ['billing.view'],
                'tags' => [],
                'related' => [],
                'in_common' => true,
                'common_sort' => 1,
            ],
            [
                'key' => 'public',
                'title' => 'Everyone',
                'aliases' => [],
                'category' => 'C',
                'short_answer' => 'S',
                'steps' => [],
                'route_name' => null,
                'route_bindings' => [],
                'page_label' => 'P',
                'permissions' => [],
                'tags' => [],
                'related' => [],
                'in_common' => true,
                'common_sort' => 2,
            ],
        ]]);

        $service = app(HelpActionService::class);
        $out = $service->forRequest(null, [], null);

        $keys = array_column($out['common'], 'key');
        $this->assertContains('public', $keys);
        $this->assertNotContains('secret', $keys);

        $out2 = $service->forRequest(null, ['billing.view'], null);
        $keys2 = array_column($out2['common'], 'key');
        $this->assertContains('secret', $keys2);
    }

    public function test_serializes_safe_route_and_url_for_simple_named_route(): void
    {
        config(['help_actions.actions' => [
            [
                'key' => 'assets',
                'title' => 'Assets',
                'aliases' => [],
                'category' => 'C',
                'short_answer' => 'S',
                'steps' => [],
                'route_name' => 'assets.index',
                'route_bindings' => [],
                'page_label' => 'Assets',
                'permissions' => [],
                'tags' => [],
                'related' => [],
                'in_common' => false,
                'common_sort' => 0,
            ],
        ]]);

        $service = app(HelpActionService::class);
        $out = $service->forRequest(null, [], null);
        $this->assertCount(0, $out['common']);

        $outSearch = $service->forRequest('Assets', [], null);
        $this->assertNotEmpty($outSearch['results']);
        $row = $outSearch['results'][0];
        $this->assertSame('assets.index', $row['route_name']);
        $this->assertIsString($row['url']);
        $this->assertStringContainsString('/app/assets', $row['url']);
    }

    public function test_brand_route_returns_null_url_without_active_brand(): void
    {
        config(['help_actions.actions' => [
            [
                'key' => 'brand_edit',
                'title' => 'Brand',
                'aliases' => [],
                'category' => 'C',
                'short_answer' => 'S',
                'steps' => [],
                'route_name' => 'brands.edit',
                'route_bindings' => ['brand' => 'active_brand'],
                'page_label' => 'Brand',
                'permissions' => [],
                'tags' => [],
                'related' => [],
                'in_common' => false,
                'common_sort' => 0,
            ],
        ]]);

        $service = app(HelpActionService::class);
        $out = $service->forRequest('Brand', [], null);
        $this->assertSame('brands.edit', $out['results'][0]['route_name']);
        $this->assertNull($out['results'][0]['url']);

        $brand = new Brand;
        $brand->id = 999;
        $brand->exists = true;

        $out2 = $service->forRequest('Brand', [], $brand);
        $this->assertNotNull($out2['results'][0]['url']);
        $this->assertStringContainsString('999', $out2['results'][0]['url']);
    }

    public function test_related_includes_resolved_targets_without_nested_related(): void
    {
        config(['help_actions.actions' => [
            [
                'key' => 'parent',
                'title' => 'Parent',
                'aliases' => [],
                'category' => 'C',
                'short_answer' => 'S',
                'steps' => [],
                'route_name' => null,
                'route_bindings' => [],
                'page_label' => 'P',
                'permissions' => [],
                'tags' => [],
                'related' => ['child'],
                'in_common' => false,
                'common_sort' => 0,
            ],
            [
                'key' => 'child',
                'title' => 'Child',
                'aliases' => [],
                'category' => 'C',
                'short_answer' => 'Child answer',
                'steps' => ['Step a'],
                'route_name' => null,
                'route_bindings' => [],
                'page_label' => 'Child page',
                'permissions' => [],
                'tags' => [],
                'related' => [],
                'in_common' => false,
                'common_sort' => 0,
            ],
        ]]);

        $service = app(HelpActionService::class);
        $out = $service->forRequest('Parent', [], null);
        $this->assertCount(1, $out['results'][0]['related']);
        $rel = $out['results'][0]['related'][0];
        $this->assertSame('child', $rel['key']);
        $this->assertSame('Child answer', $rel['short_answer']);
        $this->assertSame([], $rel['related']);
    }

    public function test_empty_string_query_behaves_like_no_query(): void
    {
        config(['help_actions.actions' => [
            [
                'key' => 'c1',
                'title' => 'Common',
                'aliases' => [],
                'category' => 'C',
                'short_answer' => 'S',
                'steps' => [],
                'route_name' => null,
                'route_bindings' => [],
                'page_label' => 'P',
                'permissions' => [],
                'tags' => [],
                'related' => [],
                'in_common' => true,
                'common_sort' => 1,
            ],
        ]]);

        $service = app(HelpActionService::class);
        $a = $service->forRequest(null, [], null);
        $b = $service->forRequest('', [], null);
        $this->assertEquals($a, $b);
        $this->assertNull($b['query']);
        $this->assertNotEmpty($b['common']);
    }

    public function test_search_with_no_matches_returns_empty_results(): void
    {
        config(['help_actions.actions' => [
            [
                'key' => 'only',
                'title' => 'Alpha only',
                'aliases' => [],
                'category' => 'Cat',
                'short_answer' => 'S',
                'steps' => [],
                'route_name' => null,
                'route_bindings' => [],
                'page_label' => 'P',
                'permissions' => [],
                'tags' => [],
                'related' => [],
                'in_common' => false,
                'common_sort' => 0,
            ],
        ]]);

        $service = app(HelpActionService::class);
        $out = $service->forRequest('zzzznomatchzzzz', [], null);
        $this->assertSame('zzzznomatchzzzz', $out['query']);
        $this->assertSame([], $out['results']);
    }

    public function test_missing_route_name_serializes_null_url_without_throw(): void
    {
        config(['help_actions.actions' => [
            [
                'key' => 'noroute',
                'title' => 'T',
                'aliases' => [],
                'category' => 'C',
                'short_answer' => 'S',
                'steps' => [],
                'route_name' => null,
                'route_bindings' => [],
                'page_label' => 'P',
                'permissions' => [],
                'tags' => [],
                'related' => [],
                'in_common' => false,
                'common_sort' => 0,
            ],
            [
                'key' => 'badroute',
                'title' => 'Bad route topic',
                'aliases' => [],
                'category' => 'C',
                'short_answer' => 'S',
                'steps' => [],
                'route_name' => 'this.route.name.does.not.exist.on.purpose',
                'route_bindings' => [],
                'page_label' => 'P',
                'permissions' => [],
                'tags' => [],
                'related' => [],
                'in_common' => false,
                'common_sort' => 0,
            ],
        ]]);

        $service = app(HelpActionService::class);
        $out = $service->forRequest('Bad route', [], null);
        $this->assertCount(1, $out['results']);
        $this->assertNull($out['results'][0]['url']);
        $this->assertSame('this.route.name.does.not.exist.on.purpose', $out['results'][0]['route_name']);
    }

    public function test_related_omits_targets_user_cannot_access(): void
    {
        config(['help_actions.actions' => [
            [
                'key' => 'parent',
                'title' => 'Parent',
                'aliases' => [],
                'category' => 'C',
                'short_answer' => 'S',
                'steps' => [],
                'route_name' => null,
                'route_bindings' => [],
                'page_label' => 'P',
                'permissions' => [],
                'tags' => [],
                'related' => ['secret', 'child'],
                'in_common' => false,
                'common_sort' => 0,
            ],
            [
                'key' => 'secret',
                'title' => 'Secret child',
                'aliases' => [],
                'category' => 'C',
                'short_answer' => 'Hidden',
                'steps' => [],
                'route_name' => null,
                'route_bindings' => [],
                'page_label' => 'P',
                'permissions' => ['billing.view'],
                'tags' => [],
                'related' => [],
                'in_common' => false,
                'common_sort' => 0,
            ],
            [
                'key' => 'child',
                'title' => 'Public child',
                'aliases' => [],
                'category' => 'C',
                'short_answer' => 'Visible',
                'steps' => [],
                'route_name' => null,
                'route_bindings' => [],
                'page_label' => 'P',
                'permissions' => [],
                'tags' => [],
                'related' => [],
                'in_common' => false,
                'common_sort' => 0,
            ],
        ]]);

        $service = app(HelpActionService::class);
        $out = $service->forRequest('Parent', [], null);
        $related = $out['results'][0]['related'];
        $this->assertCount(1, $related);
        $this->assertSame('child', $related[0]['key']);
    }

    public function test_malformed_action_entries_are_skipped_or_sanitized(): void
    {
        config(['help_actions.actions' => [
            'not-an-array',
            [
                'key' => '',
                'title' => 'No key',
                'aliases' => [],
                'category' => 'C',
                'short_answer' => 'S',
                'steps' => [],
                'route_name' => null,
                'route_bindings' => [],
                'page_label' => 'P',
                'permissions' => [],
                'tags' => [],
                'related' => [],
                'in_common' => true,
                'common_sort' => 1,
            ],
            [
                'key' => 'ok',
                'title' => 'OK',
                'aliases' => [],
                'category' => 'C',
                'short_answer' => 'S',
                'steps' => ['a', 99, ['nested'], 'b'],
                'route_name' => null,
                'route_bindings' => [],
                'page_label' => 'P',
                'permissions' => [],
                'tags' => [1, 'tag', null],
                'related' => [],
                'in_common' => true,
                'common_sort' => 2,
            ],
        ]]);

        $service = app(HelpActionService::class);
        $out = $service->forRequest('OK', [], null);
        $this->assertCount(1, $out['results']);
        $row = $out['results'][0];
        $this->assertSame(['a', '99', 'b'], $row['steps']);
        $this->assertSame(['1', 'tag'], $row['tags']);
    }

    public function test_non_array_permissions_in_config_treated_as_public(): void
    {
        config(['help_actions.actions' => [
            [
                'key' => 'wide',
                'title' => 'Wide',
                'aliases' => [],
                'category' => 'C',
                'short_answer' => 'S',
                'steps' => [],
                'route_name' => null,
                'route_bindings' => [],
                'page_label' => 'P',
                'permissions' => 'billing.view',
                'tags' => [],
                'related' => [],
                'in_common' => true,
                'common_sort' => 1,
            ],
        ]]);

        $service = app(HelpActionService::class);
        $out = $service->forRequest(null, [], null);
        $this->assertCount(1, $out['common']);
        $this->assertSame('wide', $out['common'][0]['key']);
    }
}
