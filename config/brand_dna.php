<?php

return [
    // In local env, default true so queue worker always uses visual pipeline. Override with env to disable.
    'visual_page_extraction_enabled' => filter_var(env('BRAND_DNA_VISUAL_PAGE_EXTRACTION_ENABLED', env('APP_ENV') === 'local' ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN),
];
