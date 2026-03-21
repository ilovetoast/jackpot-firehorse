<?php

/**
 * Engine that proposes new metadata option values from tags + existing/candidate data.
 *
 * tag_inferred_field_keys: field keys where high-frequency asset tags may become
 * select options (e.g. fish_species). Leave empty to skip tag_cluster suggestions.
 */
return [
    'min_occurrence' => 8,
    'min_ratio' => 0.2,

    /** @var list<string> */
    'tag_inferred_field_keys' => [],
];
