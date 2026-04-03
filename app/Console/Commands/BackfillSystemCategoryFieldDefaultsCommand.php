<?php

namespace App\Console\Commands;

use App\Models\SystemCategoryFieldDefault;
use App\Services\SystemCategoryService;
use App\Services\TenantMetadataVisibilityService;
use Illuminate\Console\Command;

class BackfillSystemCategoryFieldDefaultsCommand extends Command
{
    protected $signature = 'metadata:backfill-system-category-field-defaults {--force : Replace existing rows for each template}';

    protected $description = 'Populate system_category_field_defaults from config/metadata_category_defaults.php (latest template per slug)';

    public function handle(SystemCategoryService $systemCategoryService, TenantMetadataVisibilityService $visibilityService): int
    {
        $templates = $systemCategoryService->getAllTemplates();
        $written = 0;

        foreach ($templates as $template) {
            $slug = $template->slug;
            $at = $template->asset_type->value;
            $map = $visibilityService->buildConfigDefaultsMapForSystemTemplate($slug, $at);

            if ($this->option('force')) {
                SystemCategoryFieldDefault::query()->where('system_category_id', $template->id)->delete();
            }

            foreach ($map as $fieldId => $visibility) {
                if (! $this->option('force')) {
                    $exists = SystemCategoryFieldDefault::query()
                        ->where('system_category_id', $template->id)
                        ->where('metadata_field_id', $fieldId)
                        ->exists();
                    if ($exists) {
                        continue;
                    }
                }

                SystemCategoryFieldDefault::query()->updateOrCreate(
                    [
                        'system_category_id' => $template->id,
                        'metadata_field_id' => $fieldId,
                    ],
                    [
                        'is_hidden' => $visibility['is_hidden'],
                        'is_upload_hidden' => $visibility['is_upload_hidden'],
                        'is_filter_hidden' => $visibility['is_filter_hidden'],
                        'is_edit_hidden' => $visibility['is_edit_hidden'] ?? false,
                        'is_primary' => array_key_exists('is_primary', $visibility) ? $visibility['is_primary'] : null,
                    ]
                );
                $written++;
            }

            $this->line("Template {$slug} ({$at}): ".count($map).' field rows');
        }

        $this->info("Done. Upserts touched ~{$written} field row(s).");

        return self::SUCCESS;
    }
}
