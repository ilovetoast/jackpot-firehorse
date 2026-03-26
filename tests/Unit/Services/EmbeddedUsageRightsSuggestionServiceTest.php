<?php

namespace Tests\Unit\Services;

use App\Services\EmbeddedUsageRightsSuggestionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmbeddedUsageRightsSuggestionServiceTest extends TestCase
{
    #[Test]
    public function source_constant_is_stable_for_api_and_ui(): void
    {
        $this->assertSame('jackpot_embedded', EmbeddedUsageRightsSuggestionService::SOURCE);
    }
}
