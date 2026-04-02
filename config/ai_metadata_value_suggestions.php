<?php

/**
 * Engine that proposes new metadata option values from tags + existing/candidate data.
 *
 * tag_inferred_field_keys: field keys where high-frequency asset tags may become
 * select options (e.g. fish_species). Leave empty to skip tag_cluster suggestions.
 *
 * min_option_length_for_tag_substring_match: When inferring tag → field from option catalogs,
 * substring fallback requires the option token to be at least this long before we treat
 * "tag contains option" as a match. Prevents short values like resolution "high" matching
 * inside unrelated tags (e.g. high-rise-legging-select).
 */
return [
    'min_occurrence' => 8,
    'min_ratio' => 0.2,

    /** @var list<string> */
    'tag_inferred_field_keys' => [],

    /** @var list<string> Extra field keys to exclude from merged-signal value suggestions (in addition to automatic population). */
    'excluded_field_keys' => [],

    'min_option_length_for_tag_substring_match' => 6,
];
