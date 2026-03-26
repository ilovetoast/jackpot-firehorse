<?php

/**
 * System suggestions for usage_rights from embedded file metadata (e.g. IPTC copyright notice).
 * Does not use AI quota. Complements AiMetadataSuggestionJob.
 */
return [
    'enabled' => env('EMBEDDED_LICENSING_SUGGESTIONS_ENABLED', true),

    /** Select option value to suggest when embedded rights-like text is detected */
    'suggest_value' => 'licensed',

    /** Minimum length of copyright_notice text before pattern checks */
    'min_copyright_length' => 12,
];
