<?php

use App\Models\Asset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Backfill title for existing assets by deriving from original_filename.
     * Safe operation: only updates rows where title is null.
     */
    public function up(): void
    {
        // Only process assets where title is null (backfill safety)
        Asset::whereNull('title')->chunkById(100, function ($assets) {
            foreach ($assets as $asset) {
                $title = $this->deriveTitleFromFilename($asset->original_filename);
                
                // Update in chunks for better performance
                DB::table('assets')
                    ->where('id', $asset->id)
                    ->update(['title' => $title]);
            }
        });
    }

    /**
     * Reverse the migrations.
     * 
     * Note: This does not restore titles since they were derived from filenames.
     * Set titles to null to revert to original state.
     */
    public function down(): void
    {
        // Optionally set titles back to null for assets that were backfilled
        // This is safe but will lose any manual edits to titles
        // For safety, we'll leave titles as-is (they're derived anyway)
        // If you need to revert, manually set titles to null
    }

    /**
     * Derive human-readable title from filename.
     * 
     * Strips extension and converts slug to human-readable form.
     * Example: "my-awesome-image.jpg" -> "My Awesome Image"
     * Example: "MY_FILE_NAME.PNG" -> "My File Name"
     * 
     * @param string $filename
     * @return string
     */
    protected function deriveTitleFromFilename(string $filename): string
    {
        // Strip extension
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $nameWithoutExtension = $extension ? substr($filename, 0, -(strlen($extension) + 1)) : $filename;
        
        // Replace hyphens and underscores with spaces
        $withSpaces = str_replace(['-', '_'], ' ', $nameWithoutExtension);
        
        // Trim and collapse multiple spaces
        $trimmed = trim(preg_replace('/\s+/', ' ', $withSpaces));
        
        // Capitalize first letter of each word
        return ucwords(strtolower($trimmed));
    }
};
