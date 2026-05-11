<?php

namespace Tests\Unit\Services;

use App\Services\SvgSanitizationService;
use Tests\TestCase;

class SvgSanitizationServiceTest extends TestCase
{
    protected SvgSanitizationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(SvgSanitizationService::class);
    }

    public function test_strips_script_tag(): void
    {
        $dirty = <<<'XML'
<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">
  <script>alert(1)</script>
  <rect width="10" height="10" fill="red" />
</svg>
XML;

        $clean = $this->svc->sanitize($dirty);

        $this->assertNotNull($clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('alert', $clean);
        $this->assertStringContainsString('<rect', $clean);
    }

    public function test_strips_event_handler_attributes(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg"><rect onclick="alert(1)" onload="x()" width="10" height="10"/></svg>';

        $clean = $this->svc->sanitize($dirty);

        $this->assertNotNull($clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onload', $clean);
    }

    public function test_strips_javascript_href(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><rect width="10" height="10"/></a></svg>';

        $clean = $this->svc->sanitize($dirty);

        $this->assertNotNull($clean);
        $this->assertStringNotContainsString('javascript:', $clean);
    }

    public function test_returns_null_for_non_svg_input(): void
    {
        $this->assertNull($this->svc->sanitize(''));
        $this->assertNull($this->svc->sanitize('not svg at all'));
        $this->assertNull($this->svc->sanitize('<html><body>nope</body></html>'));
    }

    public function test_clean_svg_passes_through_largely_unchanged(): void
    {
        $clean = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="red"/></svg>';

        $result = $this->svc->sanitize($clean);

        $this->assertNotNull($result);
        $this->assertStringContainsString('<rect', $result);
        $this->assertStringContainsString('fill="red"', $result);
    }
}
