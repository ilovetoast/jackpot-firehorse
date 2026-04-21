<?php

/**
 * Opt-in routes for Playwright / local golden-path testing (Studio Versions).
 * Never enable in production.
 */
return [
    'enabled' => (bool) env('E2E_STUDIO_VERSIONS_ENABLED', false),

    /** Shared secret for GET /__e2e__/studio-versions/bootstrap — use a long random value locally. */
    'token' => (string) env('E2E_STUDIO_VERSIONS_TOKEN', ''),
];
