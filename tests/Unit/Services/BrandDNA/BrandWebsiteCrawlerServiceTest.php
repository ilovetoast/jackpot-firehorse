<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\BrandWebsiteCrawlerService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrandWebsiteCrawlerServiceTest extends TestCase
{
    public function test_json_ld_logo_is_extracted_with_high_priority(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html><html><head>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"Organization","name":"Acme","logo":"https://cdn.example.com/brand-mark.png"}
</script>
</head><body>
<img src="https://cdn.example.com/hero-social-share-1200.jpg" alt="share">
</body></html>
HTML;

        Http::fake(fn () => Http::response($html, 200));

        $crawler = app(BrandWebsiteCrawlerService::class);
        $result = $crawler->crawl('https://logo-fixture.test');

        $this->assertSame('https://cdn.example.com/brand-mark.png', $result['logo_url']);
        $this->assertContains('https://cdn.example.com/brand-mark.png', $result['logo_candidates']);
        $this->assertNotEmpty($result['logo_candidate_entries']);
        $this->assertSame('https://cdn.example.com/brand-mark.png', $result['logo_candidate_entries'][0]['url']);
    }

    public function test_lazy_img_uses_data_src_and_srcset_when_src_empty(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html><html><head></head><body>
<header>
<a href="/">
<img class="header-brand" alt="Co" src="" data-src="https://cdn.example.com/lazy-logo.png"
  srcset="https://cdn.example.com/lazy-logo.png?w=300 300w, https://cdn.example.com/lazy-logo.png?w=600 600w">
</a>
</header>
</body></html>
HTML;

        Http::fake(fn () => Http::response($html, 200));

        $crawler = app(BrandWebsiteCrawlerService::class);
        $result = $crawler->crawl('https://lazy-logo.test');

        $this->assertNotNull($result['logo_url']);
        $this->assertStringContainsString('lazy-logo.png', $result['logo_url']);
    }

    public function test_css_background_on_header_logo_container_is_collected(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html><html><head></head><body>
<header>
<div class="site-logo" style="background-image: url(//cdn.example.com/bg-logo.svg); width:120px;height:40px"></div>
</header>
</body></html>
HTML;

        Http::fake(fn () => Http::response($html, 200));

        $crawler = app(BrandWebsiteCrawlerService::class);
        $result = $crawler->crawl('https://bg-logo.test');

        $this->assertNotNull($result['logo_url']);
        $this->assertStringContainsString('bg-logo.svg', $result['logo_url']);
    }
}
