<?php

/**
 * Predefined headline appearance options for brand typography / guidelines.
 * IDs are stable API keys; labels copy can change. Claude extraction must only emit these IDs.
 */
return [
    'options' => [
        [
            'id' => 'leading_accent',
            'label' => 'Leading accent or dash',
            'description' => 'Headlines start with an accent mark, em dash, or decorative lead-in.',
        ],
        [
            'id' => 'all_caps',
            'label' => 'ALL CAPS display',
            'description' => 'Display headlines set in all capitals.',
        ],
        [
            'id' => 'title_case',
            'label' => 'Title Case',
            'description' => 'Major words capitalized (headline-style title case).',
        ],
        [
            'id' => 'sentence_case',
            'label' => 'Sentence case',
            'description' => 'Headlines read like a sentence with normal capitalization.',
        ],
        [
            'id' => 'headline_font_distinct',
            'label' => 'Distinct headline font',
            'description' => 'Headlines use a different font family or role than body copy.',
        ],
        [
            'id' => 'container_shape',
            'label' => 'Container or shape',
            'description' => 'Headlines sit inside a box, pill, badge, or shaped panel.',
        ],
        [
            'id' => 'border_or_rule',
            'label' => 'Border or rule',
            'description' => 'Underline, hairline, frame, or horizontal rule tied to the headline.',
        ],
        [
            'id' => 'tracking_wide',
            'label' => 'Wide letter-spacing',
            'description' => 'Open tracking / expanded letter-spacing on headlines.',
        ],
        [
            'id' => 'tracking_tight',
            'label' => 'Tight tracking',
            'description' => 'Condensed or tight letter-spacing on headlines.',
        ],
    ],
];
