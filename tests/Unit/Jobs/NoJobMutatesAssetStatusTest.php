<?php

namespace Tests\Unit\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * CI Check: Ensure no Job class mutates Asset.status
 *
 * This test scans all Job classes and ensures they don't contain
 * code that mutates Asset.status. This is a static analysis check
 * that runs in CI to prevent regressions.
 *
 * Asset.status represents VISIBILITY only (VISIBLE/HIDDEN/FAILED),
 * not processing state. Jobs must track progress via thumbnail_status,
 * metadata flags, and activity events.
 */
class NoJobMutatesAssetStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that no Job class contains code that mutates Asset.status.
     *
     * This is a CI check that scans job files for patterns that indicate
     * status mutations. It fails if any job attempts to mutate status.
     */
    public function test_no_job_class_mutates_asset_status(): void
    {
        $jobsPath = app_path('Jobs');
        $jobFiles = $this->getJobFiles($jobsPath);
        
        $violations = [];
        
        foreach ($jobFiles as $jobFile) {
            $className = $this->getClassNameFromFile($jobFile);
            if (!$className) {
                continue;
            }
            
            // Skip abstract classes and base classes
            if (str_contains($className, 'Abstract') || str_contains($className, 'Base')) {
                continue;
            }
            
            $content = file_get_contents($jobFile);
            
            // Check for patterns that indicate status mutation
            // Look for direct status assignments or updates
            $lines = explode("\n", $content);
            
            foreach ($lines as $lineNumber => $line) {
                $lineNumber = $lineNumber + 1; // 1-indexed
                $trimmedLine = trim($line);
                
                // Skip comments
                if (str_starts_with($trimmedLine, '//') || 
                    str_starts_with($trimmedLine, '*') ||
                    str_starts_with($trimmedLine, '#')) {
                    continue;
                }
                
                // Skip docblocks
                if (str_starts_with($trimmedLine, '/**') || str_starts_with($trimmedLine, '*/')) {
                    continue;
                }
                
                // Check for status mutations (actual assignments/updates, not reads)
                // Pattern 1: Direct assignment $asset->status = ...
                if (preg_match('/\$asset\s*->\s*status\s*=/', $line)) {
                    $violations[] = [
                        'file' => basename($jobFile),
                        'class' => $className,
                        'line' => $lineNumber,
                        'code' => trim($line),
                        'reason' => 'Direct status assignment detected',
                    ];
                    break; // Only report once per file
                }
                
                // Pattern 2: Update array with 'status' key
                if (preg_match("/->update\s*\(\s*\[/", $line)) {
                    // Check if this line or next few lines contain 'status' =>
                    $nextLines = array_slice($lines, $lineNumber - 1, 5, true); // Get up to 5 lines
                    $context = implode("\n", $nextLines);
                    if (preg_match("/['\"]status['\"]\s*=>/", $context)) {
                        $violations[] = [
                            'file' => basename($jobFile),
                            'class' => $className,
                            'line' => $lineNumber,
                            'code' => trim($line),
                            'reason' => 'Update with status key detected',
                        ];
                        break; // Only report once per file
                    }
                }
            }
        }
        
        if (!empty($violations)) {
            $message = "The following Job classes contain code that mutates Asset.status:\n\n";
            foreach ($violations as $violation) {
                $message .= sprintf(
                    "  - %s (line %d): %s\n    Code: %s\n",
                    $violation['class'],
                    $violation['line'],
                    $violation['reason'],
                    $violation['code']
                );
            }
            $message .= "\nAsset.status represents VISIBILITY only (VISIBLE/HIDDEN/FAILED), not processing state.\n";
            $message .= "Jobs must track progress via thumbnail_status, metadata flags, and activity events.\n";
            $message .= "Only AssetProcessingFailureService is authorized to change Asset.status.\n";
            
            $this->fail($message);
        }
        
        // Test passes if no violations found
        $this->assertTrue(true, 'No Job classes mutate Asset.status');
    }
    
    /**
     * Get all PHP files in the Jobs directory (recursive).
     */
    private function getJobFiles(string $directory): array
    {
        $files = [];
        
        if (!is_dir($directory)) {
            return $files;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }
    
    /**
     * Extract class name from PHP file.
     */
    private function getClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        
        // Try to extract namespace and class name
        $namespace = null;
        $className = null;
        
        if (preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatches)) {
            $namespace = $namespaceMatches[1];
        }
        
        if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
            $className = $classMatches[1];
        }
        
        if ($className) {
            return $namespace ? $namespace . '\\' . $className : $className;
        }
        
        return null;
    }
}
