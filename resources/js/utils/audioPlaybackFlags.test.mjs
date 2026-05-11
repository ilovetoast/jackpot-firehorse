import test from 'node:test'
import assert from 'node:assert/strict'

/*
 * Locks the audio playback feature-flag contract that fixes the staging
 * bug where every audio asset failed with `NotSupportedError: the element
 * has no supported sources`. Root cause: `<audio crossOrigin="anonymous">`
 * gates every source on CORS-correct responses, and CloudFront in front
 * of S3 strips CORS headers without an explicit response-headers policy.
 *
 * Resolution: live analyser is now opt-in via config/assets.php
 * `audio.live_analyser_enabled`, surfaced to the frontend as
 * `pageProps.audioPlayback.live_analyser_enabled`. When the flag is
 * absent / false / non-true we MUST treat it as disabled so playback
 * always works.
 */

function withWindow(setup, fn) {
    const prevWindow = globalThis.window
    const prevDocument = globalThis.document
    globalThis.window = setup.window
    globalThis.document = setup.document
    try {
        fn()
    } finally {
        if (prevWindow === undefined) delete globalThis.window
        else globalThis.window = prevWindow
        if (prevDocument === undefined) delete globalThis.document
        else globalThis.document = prevDocument
    }
}

async function freshFlagsModule() {
    // Fresh import per test so module-level caches (none today, but safe
    // for future cache layers) cannot bleed across cases.
    const url = new URL('./audioPlaybackFlags.js', import.meta.url).href + '?t=' + Math.random()
    const mod = await import(url)
    return mod
}

test('flag is false when no Inertia page data is available (server / pre-hydration)', async () => {
    const { isLiveAudioAnalyserEnabled } = await freshFlagsModule()
    withWindow({ window: undefined, document: undefined }, () => {
        assert.equal(isLiveAudioAnalyserEnabled(), false)
    })
})

test('flag is true when Inertia exposes audioPlayback.live_analyser_enabled === true', async () => {
    const { isLiveAudioAnalyserEnabled } = await freshFlagsModule()
    withWindow({
        window: {
            __inertia: { page: { props: { audioPlayback: { live_analyser_enabled: true } } } },
        },
        document: { getElementById: () => null },
    }, () => {
        assert.equal(isLiveAudioAnalyserEnabled(), true)
    })
})

test('flag defaults to false when the prop is omitted', async () => {
    const { isLiveAudioAnalyserEnabled } = await freshFlagsModule()
    withWindow({
        window: { __inertia: { page: { props: {} } } },
        document: { getElementById: () => null },
    }, () => {
        assert.equal(isLiveAudioAnalyserEnabled(), false)
    })
})

test('truthy-but-not-true values do NOT enable the flag (avoids "1" / "true" string surprises)', async () => {
    const { isLiveAudioAnalyserEnabled } = await freshFlagsModule()
    for (const truthy of ['true', '1', 1, 'yes']) {
        withWindow({
            window: { __inertia: { page: { props: { audioPlayback: { live_analyser_enabled: truthy } } } } },
            document: { getElementById: () => null },
        }, () => {
            assert.equal(isLiveAudioAnalyserEnabled(), false,
                `value ${JSON.stringify(truthy)} must NOT enable analyser — only strict boolean true does`)
        })
    }
})

test('falls back to data-page hydration attribute when window.__inertia is missing', async () => {
    const { isLiveAudioAnalyserEnabled } = await freshFlagsModule()
    withWindow({
        window: {},
        document: {
            getElementById: (id) => id === 'app' ? {
                dataset: {
                    page: JSON.stringify({ props: { audioPlayback: { live_analyser_enabled: true } } }),
                },
            } : null,
        },
    }, () => {
        assert.equal(isLiveAudioAnalyserEnabled(), true)
    })
})

test('malformed data-page JSON does not throw — returns false', async () => {
    const { isLiveAudioAnalyserEnabled } = await freshFlagsModule()
    withWindow({
        window: {},
        document: {
            getElementById: () => ({ dataset: { page: 'not valid json {' } }),
        },
    }, () => {
        assert.equal(isLiveAudioAnalyserEnabled(), false,
            'A bad SSR payload must NOT crash the audio component — defensive parse is critical for staging')
    })
})
