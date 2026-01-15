<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\PdfToImage\Pdf;

/**
 * Verify PDF Thumbnail Support Command
 *
 * This command verifies that PDF thumbnail generation is properly configured
 * by testing the conversion of page 1 of a PDF to a PNG image.
 *
 * IMPORTANT: This is a verification/validation command only, not production logic.
 * It does not modify the thumbnail pipeline or any existing jobs.
 *
 * Usage:
 *   php artisan pdf:verify
 *   php artisan pdf:verify --pdf=/path/to/test.pdf
 */
class VerifyPdfThumbnailSupport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pdf:verify 
                            {--pdf= : Path to a test PDF file (optional, will create a test PDF if not provided)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify PDF thumbnail generation support (ImageMagick, Ghostscript, PHP Imagick)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Verifying PDF thumbnail generation support...');
        $this->newLine();

        // Check 1: PHP Imagick extension
        $this->info('1. Checking PHP Imagick extension...');
        if (!extension_loaded('imagick')) {
            $this->error('   ❌ PHP Imagick extension is not loaded');
            return Command::FAILURE;
        }
        $this->info('   ✅ PHP Imagick extension is loaded');

        try {
            $imagick = new \Imagick();
            $version = $imagick->getVersion();
            $this->info("   ✅ Imagick version: {$version['versionString']}");
        } catch (\Exception $e) {
            $this->error("   ❌ Failed to create Imagick instance: {$e->getMessage()}");
            return Command::FAILURE;
        }

        // Check 2: ImageMagick command-line tool
        $this->newLine();
        $this->info('2. Checking ImageMagick command-line tool...');
        $convertVersion = shell_exec('convert -version 2>&1');
        if (!$convertVersion || strpos($convertVersion, 'ImageMagick') === false) {
            $this->error('   ❌ ImageMagick command-line tool not found');
            return Command::FAILURE;
        }
        $this->info('   ✅ ImageMagick command-line tool is available');
        $this->line("   Version info: " . explode("\n", $convertVersion)[0]);

        // Check 3: Ghostscript
        $this->newLine();
        $this->info('3. Checking Ghostscript...');
        $gsVersion = shell_exec('gs --version 2>&1');
        if (!$gsVersion || !preg_match('/^\d+\.\d+/', trim($gsVersion))) {
            $this->error('   ❌ Ghostscript not found or version check failed');
            return Command::FAILURE;
        }
        $this->info("   ✅ Ghostscript is available (version: " . trim($gsVersion) . ")");

        // Check 4: ImageMagick PDF policy
        $this->newLine();
        $this->info('4. Checking ImageMagick PDF policy...');
        $policyFiles = [
            '/etc/ImageMagick-6/policy.xml',
            '/etc/ImageMagick-7/policy.xml',
        ];
        $policyFound = false;
        foreach ($policyFiles as $policyFile) {
            if (file_exists($policyFile)) {
                $policyContent = file_get_contents($policyFile);
                if (preg_match('/<policy domain="coder" rights="[^"]*" pattern="PDF"/', $policyContent)) {
                    if (preg_match('/rights="read\|write"/', $policyContent)) {
                        $this->info("   ✅ PDF policy allows read|write in {$policyFile}");
                        $policyFound = true;
                        break;
                    } else {
                        $this->warn("   ⚠️  PDF policy found but may not allow read|write in {$policyFile}");
                    }
                }
            }
        }
        if (!$policyFound) {
            $this->warn('   ⚠️  Could not verify PDF policy (may still work)');
        }

        // Check 5: Spatie PDF-to-Image package
        $this->newLine();
        $this->info('5. Checking Spatie PDF-to-Image package...');
        if (!class_exists(\Spatie\PdfToImage\Pdf::class)) {
            $this->error('   ❌ Spatie PDF-to-Image package not found');
            $this->line('   Run: composer require spatie/pdf-to-image');
            return Command::FAILURE;
        }
        $this->info('   ✅ Spatie PDF-to-Image package is available');

        // Check 6: Test PDF conversion (if PDF provided or create test PDF)
        $this->newLine();
        $this->info('6. Testing PDF to image conversion...');
        
        $testPdfPath = $this->option('pdf');
        $testPdfCreated = false;

        if (!$testPdfPath || !file_exists($testPdfPath)) {
            // Create a simple test PDF using Ghostscript
            $testPdfPath = storage_path('app/test-pdf-verify.pdf');
            $this->line('   Creating test PDF...');
            
            $psContent = <<<'PS'
%!PS
/Times-Roman findfont 24 scalefont setfont
100 700 moveto
(PDF Thumbnail Test) show
showpage
PS;
            
            file_put_contents(storage_path('app/test-pdf-verify.ps'), $psContent);
            $result = shell_exec("gs -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -sOutputFile={$testPdfPath} " . storage_path('app/test-pdf-verify.ps') . " 2>&1");
            
            if (!file_exists($testPdfPath)) {
                $this->error('   ❌ Failed to create test PDF');
                $this->line("   Ghostscript output: {$result}");
                return Command::FAILURE;
            }
            
            $testPdfCreated = true;
            $this->line("   ✅ Test PDF created: {$testPdfPath}");
        }

        try {
            $pdf = new Pdf($testPdfPath);
            $outputPath = storage_path('app/test-pdf-thumbnail-output.png');
            
            // Convert first page to PNG
            // Note: spatie/pdf-to-image v3.x uses selectPage() and save() methods
            // The output format is determined by the file extension (.png)
            $pdf->selectPage(1)
                ->save($outputPath);

            if (file_exists($outputPath) && filesize($outputPath) > 0) {
                $this->info("   ✅ PDF conversion successful!");
                $this->line("   Output: {$outputPath}");
                $this->line("   Size: " . filesize($outputPath) . " bytes");
                
                // Clean up test files
                if ($testPdfCreated && file_exists($testPdfPath)) {
                    unlink($testPdfPath);
                }
                if (file_exists(storage_path('app/test-pdf-verify.ps'))) {
                    unlink(storage_path('app/test-pdf-verify.ps'));
                }
                if (file_exists($outputPath)) {
                    unlink($outputPath);
                }
                
                $this->newLine();
                $this->info('✅ All checks passed! PDF thumbnail generation is ready.');
                return Command::SUCCESS;
            } else {
                $this->error('   ❌ PDF conversion failed - output file not created or empty');
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("   ❌ PDF conversion failed: {$e->getMessage()}");
            $this->line("   Exception: " . get_class($e));
            return Command::FAILURE;
        }
    }
}
