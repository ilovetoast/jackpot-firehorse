<?php

namespace Tests\Unit\Support\Typography;

use App\Support\Typography\GoogleFontStylesheetHelper;
use PHPUnit\Framework\TestCase;

class GoogleFontStylesheetHelperTest extends TestCase
{
    public function test_default_stylesheet_url_encodes_family(): void
    {
        $url = GoogleFontStylesheetHelper::defaultStylesheetUrlForFamily('DM Sans');
        $this->assertStringContainsString('fonts.googleapis.com', $url);
        $this->assertStringContainsString('DM%20Sans', $url);
        $this->assertStringContainsString('display=swap', $url);
    }

    public function test_stylesheet_url_for_google_entry_uses_custom_https_stylesheet(): void
    {
        $url = GoogleFontStylesheetHelper::stylesheetUrlForGoogleFontEntry([
            'source' => 'google',
            'name' => 'Roboto',
            'stylesheet_url' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap',
        ]);
        $this->assertSame('https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap', $url);
    }

    public function test_stylesheet_url_for_google_entry_builds_default_when_no_custom(): void
    {
        $url = GoogleFontStylesheetHelper::stylesheetUrlForGoogleFontEntry([
            'source' => 'google',
            'name' => 'Lora',
        ]);
        $this->assertNotNull($url);
        $this->assertStringContainsString('family=Lora', $url);
    }

    public function test_stylesheet_url_returns_null_for_non_google(): void
    {
        $this->assertNull(GoogleFontStylesheetHelper::stylesheetUrlForGoogleFontEntry([
            'source' => 'upload',
            'name' => 'X',
        ]));
    }
}
