<?php

namespace App\Console\Commands;

use Database\Seeders\DevelopmentDataSeeder;
use Illuminate\Console\Command;

/**
 * Run the development dummy data seeder with a configurable size.
 * Use this when SEEDER_SIZE is not passed into the container (e.g. Sail).
 *
 * Usage:
 *   sail artisan development:seed --size=small --force
 *   sail artisan development:seed --size=medium
 *   sail artisan development:seed --size=large
 */
class DevelopmentSeedCommand extends Command
{
    protected $signature = 'development:seed
                            {--size=small : Size: small, medium, or large}
                            {--force : Skip confirmation}';

    protected $description = 'Run DevelopmentDataSeeder with a size (small/medium/large). Use when testing filters and search.';

    public function handle(): int
    {
        $size = strtolower((string) $this->option('size'));
        if (!in_array($size, ['small', 'medium', 'large'], true)) {
            $this->error("Invalid size '{$size}'. Use: small, medium, or large.");
            return self::FAILURE;
        }

        putenv('SEEDER_SIZE=' . $size);
        $_ENV['SEEDER_SIZE'] = $size;
        config(['seeder_size' => $size]);

        $this->info("Running DevelopmentDataSeeder with size: {$size}");
        $seeder = new DevelopmentDataSeeder;
        $seeder->setCommand($this);
        $seeder->run();

        return self::SUCCESS;
    }
}
