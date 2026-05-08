<?php

namespace Tests\Unit\Support\Database;

use App\Support\Database\TestDatabaseSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TestDatabaseSchemaTest extends TestCase
{
    public static function permittedProvider(): array
    {
        return [
            ['mysql', 'testing', true],
            ['mysql', 'app_testing', true],
            ['mysql', 'app_test', true],
            ['mysql', 'laravel', false],
            ['sqlite', ':memory:', true],
            ['sqlite', '/tmp/foo/testing.sqlite', true],
            ['sqlite', database_path('database.sqlite'), false],
        ];
    }

    #[DataProvider('permittedProvider')]
    public function test_is_permitted_for_destructive_refresh(string $driver, string $database, bool $expected): void
    {
        $this->assertSame($expected, TestDatabaseSchema::isPermittedForDestructiveRefresh($driver, $database));
    }
}
