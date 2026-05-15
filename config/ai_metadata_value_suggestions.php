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

    /**
     * When true, do not propose a new option value for field A if the same normalized value
     * already exists on any other select/multiselect field’s catalog (metadata_options).
     * Prevents cross-dimension duplicates such as "studio" on Environment when Photo type
     * already lists "studio".
     */
    'dedupe_suggested_values_across_select_field_options' => true,

    'min_option_length_for_tag_substring_match' => 6,
];
