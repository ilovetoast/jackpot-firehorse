import test from 'node:test'
import assert from 'node:assert/strict'
import {
    buildVersionBadge,
    sanitizeCommitSha,
    formatVersionLabelFromCommitIso,
} from './jackpotConsoleBanner.js'

/*
 * Locks the console banner stamp contract:
 *  - Server-supplied commitIso8601 wins (MMDDYYYY:HHMM from that instant, UTC).
 *  - Optional short SHA is appended as a third segment: MMDDYYYY:HHMM:sha (no `v:` / `UTC` / middle dot).
 *  - SHA is sanitized so a bad deploy manifest never injects junk into the styled log.
 *  - When nothing's available we fall back to `local · MMDDYYYY:HHMM` with NO hint message.
 */

test('server iso + sha produces stamp with sha as third segment', () => {
    const out = buildVersionBadge(
        { commitIso8601: '2026-05-11T21:14:00+00:00', commitSha: 'c77c0c9abcdef' },
        null,
        null,
    )
    assert.equal(out.releaseString, '05112026:2114:c77c0c9a')
    assert.match(out.badgeText, /05112026:2114:c77c0c9a/)
    assert.equal(out.sha, 'c77c0c9a')
})

test('server iso without sha is date:time only (no third segment)', () => {
    const out = buildVersionBadge(
        { commitIso8601: '2026-05-11T21:14:00+00:00' },
        null,
        null,
    )
    assert.equal(out.releaseString, '05112026:2114')
    assert.match(out.badgeText, /05112026:2114/)
    assert.ok(!/:\d{8}$/.test(out.releaseString.trim()),
        'No SHA from server means no `:sha` suffix')
    assert.equal(out.sha, null)
})

test('falls back to local clock when no server iso anywhere', () => {
    const out = buildVersionBadge(null, null, null)
    assert.match(out.badgeText, /local · \d{8}:\d{4}/)
    assert.match(out.releaseString, /^local · \d{8}:\d{4}$/)
})

test('cached iso fills in when current page payload omits it (Inertia partial reloads)', () => {
    const out = buildVersionBadge({}, '2026-04-01T00:00:00Z', 'feedface')
    assert.equal(out.releaseString, '04012026:0000:feedface')
    assert.match(out.badgeText, /04012026:0000:feedface/)
})

test('sanitizeCommitSha lowercases and clamps to 8 chars', () => {
    assert.equal(sanitizeCommitSha('DEADBEEFCAFE1234'), 'deadbeef')
    assert.equal(sanitizeCommitSha('c77c0c9'), 'c77c0c9')
})

test('sanitizeCommitSha rejects non-hex / wrong-length values so junk never reaches the badge', () => {
    assert.equal(sanitizeCommitSha('not-a-sha'), null)
    assert.equal(sanitizeCommitSha(''), null)
    assert.equal(sanitizeCommitSha('abc'), null,
        'Too short — full SHAs are 40 chars, abbrev 7 minimum (we use 8)')
    assert.equal(sanitizeCommitSha('abcdef1<script>'), null,
        'Defense-in-depth: never echo HTML into the styled console line')
    assert.equal(sanitizeCommitSha(undefined), null)
    assert.equal(sanitizeCommitSha(123), null)
})

test('invalid iso falls back to local rather than rendering NaN', () => {
    const out = buildVersionBadge({ commitIso8601: 'not-a-date' }, null, null)
    assert.match(out.badgeText, /local · /)
    assert.equal(formatVersionLabelFromCommitIso('not-a-date'), null)
})
