import { expect, test } from 'playwright/test'

const token = process.env.E2E_STUDIO_VERSIONS_TOKEN || 'local-playwright-e2e-token'

test.describe('Studio Versions — color pack golden path', () => {
    test('Version Builder → color pack → generate 5 → rail → open a new version', async ({ page }) => {
        const res = await page.goto(`/__e2e__/studio-versions/bootstrap?token=${encodeURIComponent(token)}`)
        expect(res?.ok()).toBeTruthy()

        await page.waitForURL(/\/app\/generative\?composition=\d+/, { timeout: 60_000 })

        await expect(page.getByTestId('studio-versions-rail-scroll')).toBeVisible({ timeout: 60_000 })

        await page.getByTestId('studio-create-versions-tile').click()
        await expect(page.getByTestId('version-builder-dialog')).toBeVisible()

        await page.getByTestId('version-builder-color-pack').click()

        await expect(page.getByTestId('generate-versions-dialog')).toBeVisible({ timeout: 60_000 })
        const summary = page.getByTestId('generate-versions-selected-summary')
        await expect(summary).toContainText('5 selected outputs')

        await page.getByTestId('generate-versions-submit').click()
        await expect(page.getByTestId('generate-versions-dialog')).toBeHidden({ timeout: 60_000 })

        const tiles = page.locator('[data-studio-version-cid]')
        await expect(tiles).toHaveCount(6, { timeout: 60_000 })

        await expect(page.getByText('Black', { exact: true })).toBeVisible()
        await expect(page.getByText('Green', { exact: true })).toBeVisible()

        const last = tiles.nth(5)
        const cid = await last.getAttribute('data-studio-version-cid')
        expect(cid).toBeTruthy()
        await last.locator('button').first().click()
        await page.waitForURL(new RegExp(`[?&]composition=${cid}`), { timeout: 60_000 })
    })
})
