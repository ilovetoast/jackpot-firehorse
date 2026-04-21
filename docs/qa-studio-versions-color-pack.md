# QA checklist — Studio Versions (5 color variants)

Use with a **product-photo style** composition (main image layer + typical ad layout). For automated E2E, use `npm run test:e2e` (see `playwright.config.ts` — fake generation, no external AI).

## Environment (automated / local deterministic)

- `QUEUE_CONNECTION=sync`
- `STUDIO_GENERATION_FAKE_COMPLETE=true` (skips generative edit; duplicates only — fast, stable)
- Optional: `STUDIO_CREATIVE_SET_GENERATION_MAX_COLORS=5` to match “exactly five swatches” from presets
- E2E bootstrap (Playwright only): `E2E_STUDIO_VERSIONS_ENABLED=true`, `E2E_STUDIO_VERSIONS_TOKEN=<secret>`

## First successful run

1. Open a creative that is already in a Versions set (or use `/__e2e__/studio-versions/bootstrap?token=…` for a seeded fixture).
2. Open the Versions rail → **New versions** / **Create versions**.
3. Choose **Color pack** → Generate Versions modal opens.
4. Confirm **only color axis** is prefilled (no scene/format chips in the picker for those axes).
5. Confirm **selected outputs** count matches expected (e.g. **5** with `max_colors=5`, else first N from presets).
6. Click **Generate N versions** — modal closes; toast indicates generation started; rail updates without full page refresh.
7. After completion: **N new tiles** in the rail (plus base), labels/chips show color names (e.g. Black, Navy).
8. Click a **new** tile — URL `?composition=` updates; canvas loads without hard error.

## Repeat run

1. Run **Color pack** again from the same baseline.
2. Confirm no duplicate “dead” modals; prefill is not stale (axis counts sane).
3. Confirm set does not exceed max versions / planner limits (server error should be readable if hit).

## Partial failure

1. With **fake generation off**, simulate or observe a run where some items fail (e.g. invalid asset).
2. Toast should state **partial success** (e.g. some created, some failed) — not silent success.
3. Failed tiles show **!** / retry affordance where applicable.

## No-result behavior

1. If every item fails (or zero new compositions land in the set), user sees an explicit **no new versions** / **all failed** style message — not an empty rail with no explanation.

## Rail refresh

1. During generation, rail should reflect **generating** / new entries without manual browser refresh.
2. Polling should not visibly “fight” itself (overlapping requests); status should settle to terminal state.

## Newcomer / review

1. After a successful pack, **New** badges / post-create banner appear for unviewed newcomers.
2. **Review next** walks through newcomers; opening a version clears its “new” highlight appropriately.

## Opening created versions

1. Open each new tile; confirm composition loads and is not the wrong sibling.

## Duplicate / limits

1. Run a pack that would exceed **max versions per set** — expect a clear validation message, not a stuck modal.

## Unsaved edits

1. With **dirty** document, start generation from Version Builder — app should not lose work silently (autosave or explicit warning per product rules). Note current behavior and any gap.

## Poll failure mid-run

1. Simulate network drop (DevTools offline) mid-poll — user should see **Could not refresh generation status** (or similar), polling stops, job id cleared — no infinite silent spinner.

## Manual smoke (real AI)

1. Turn **off** `STUDIO_GENERATION_FAKE_COMPLETE`, use async queue + workers as in staging.
2. Repeat first successful run with a real product photo — expect slower completion but same UX signals.
