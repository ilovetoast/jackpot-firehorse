import { defineConfig, devices } from 'playwright/test'

const e2eToken = process.env.E2E_STUDIO_VERSIONS_TOKEN || 'local-playwright-e2e-token'

/**
 * Golden-path E2E for Studio Versions (color pack).
 *
 * Run (from `jackpot/`):
 *   npx playwright install   # first time only
 *   npm run test:e2e
 *
 * The webServer injects env vars so generation completes without external AI
 * ({@code STUDIO_GENERATION_FAKE_COMPLETE} + sync queue in .env).
 */
export default defineConfig({
    testDir: 'e2e',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    timeout: 120_000,
    expect: { timeout: 30_000 },
    use: {
        ...devices['Desktop Chrome'],
        trace: 'on-first-retry',
        baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8124',
    },
    webServer: {
        command: [
            `E2E_STUDIO_VERSIONS_ENABLED=1`,
            `E2E_STUDIO_VERSIONS_TOKEN=${e2eToken}`,
            `STUDIO_GENERATION_FAKE_COMPLETE=1`,
            `STUDIO_CREATIVE_SET_GENERATION_MAX_COLORS=5`,
            `QUEUE_CONNECTION=sync`,
            `php artisan serve --host=127.0.0.1 --port=8124`,
        ].join(' '),
        cwd: __dirname,
        url: 'http://127.0.0.1:8124/up',
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
    },
})
