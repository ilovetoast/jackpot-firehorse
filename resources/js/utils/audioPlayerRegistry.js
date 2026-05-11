/**
 * Cross-component audio playback coordinator.
 *
 * Why this exists:
 *   - Multiple AudioCardVisual instances live on the page (one per
 *     audio asset in the grid), and the asset drawer + lightbox can
 *     each have their own player too. We never want two audio sources
 *     playing simultaneously in a DAM — that's noisy and confusing.
 *   - We also want a single shared Web Audio `AudioContext` because
 *     browsers cap the number of contexts (~6) per page; one global
 *     analyser that switches its source is the safer pattern.
 *
 * Public API:
 *   const reg = getAudioRegistry()
 *   const handle = reg.attach(audioElement)   // wires Web Audio graph
 *   reg.play(token, audioElement)             // claims ownership; pauses prior
 *   reg.pause(token)                          // releases ownership if held
 *   reg.subscribe(callback)                   // notified on owner change
 *   reg.getCurrentToken()                     // null when nothing plays
 *   reg.getAnalyserData()                     // Uint8Array | null (frequency)
 *
 * `token` is any unique value; we use the asset id but anything works.
 */

let singleton = null

function createRegistry() {
    /** @type {AudioContext | null} */
    let audioCtx = null
    /** @type {AnalyserNode | null} */
    let analyser = null
    /** @type {WeakMap<HTMLAudioElement, MediaElementAudioSourceNode>} */
    const sourceCache = new WeakMap()
    /** @type {string | number | null} */
    let currentToken = null
    /** @type {HTMLAudioElement | null} */
    let currentEl = null
    /** @type {Set<(token: string|number|null) => void>} */
    const listeners = new Set()
    /** @type {Uint8Array | null} */
    let frequencyBuffer = null
    /**
     * Per-token audio element map. Lets sibling hooks (e.g. the lightbox
     * transport) read duration / currentTime / control playback through
     * the SAME element that was rendered elsewhere (e.g. AudioCardVisual)
     * without having to render a duplicate `<audio>` of their own.
     *
     * Map<token, HTMLAudioElement>
     */
    const tokenElements = new Map()

    function ensureContext() {
        if (audioCtx) return audioCtx
        const Ctx = typeof window !== 'undefined' && (window.AudioContext || window.webkitAudioContext)
        if (!Ctx) return null
        try {
            audioCtx = new Ctx()
            analyser = audioCtx.createAnalyser()
            analyser.fftSize = 128 // 64 frequency bins — plenty for bar viz, cheap to draw
            analyser.smoothingTimeConstant = 0.78
            analyser.connect(audioCtx.destination)
            frequencyBuffer = new Uint8Array(analyser.frequencyBinCount)
        } catch (e) {
            console.warn('[audioPlayerRegistry] AudioContext failed', e)
            audioCtx = null
            analyser = null
        }
        return audioCtx
    }

    function attach(audioEl) {
        if (!audioEl) return null
        const ctx = ensureContext()
        if (!ctx || !analyser) return null
        if (sourceCache.has(audioEl)) return sourceCache.get(audioEl)
        try {
            const node = ctx.createMediaElementSource(audioEl)
            node.connect(analyser)
            sourceCache.set(audioEl, node)
            return node
        } catch (e) {
            console.warn('[audioPlayerRegistry] createMediaElementSource failed', e)
            return null
        }
    }

    function notify() {
        listeners.forEach((cb) => {
            try {
                cb(currentToken)
            } catch (e) {
                console.warn('[audioPlayerRegistry] listener error', e)
            }
        })
    }

    async function play(token, audioEl) {
        if (!audioEl) return false
        // Pause any prior owner — including itself if rapidly re-pressed.
        if (currentEl && currentEl !== audioEl) {
            try {
                currentEl.pause()
            } catch (_) {}
        }
        attach(audioEl)
        const ctx = audioCtx
        if (ctx && ctx.state === 'suspended') {
            try {
                await ctx.resume()
            } catch (_) {}
        }
        currentToken = token
        currentEl = audioEl
        try {
            await audioEl.play()
        } catch (e) {
            // Autoplay blocked or other policy error — release ownership.
            currentToken = null
            currentEl = null
            notify()
            console.warn('[audioPlayerRegistry] play rejected', e)
            return false
        }
        notify()
        return true
    }

    function pause(token) {
        if (token != null && token !== currentToken) {
            // Not the owner; ignore so we don't disrupt another card.
            return
        }
        if (currentEl) {
            try {
                currentEl.pause()
            } catch (_) {}
        }
        currentToken = null
        currentEl = null
        notify()
    }

    function subscribe(cb) {
        listeners.add(cb)
        return () => listeners.delete(cb)
    }

    function getCurrentToken() {
        return currentToken
    }

    function getCurrentEl() {
        return currentEl
    }

    function getAnalyserData() {
        if (!analyser || !frequencyBuffer) return null
        analyser.getByteFrequencyData(frequencyBuffer)
        return frequencyBuffer
    }

    function getAnalyser() {
        return analyser
    }

    /**
     * Register an audio element with a token so sibling hooks can find
     * it. Called from every useAudioPlayer mount that has an audioRef.
     * Notifies listeners so observers re-resolve their effective element.
     */
    function registerEl(token, audioEl) {
        if (token == null || !audioEl) return
        const prior = tokenElements.get(token)
        if (prior === audioEl) return
        tokenElements.set(token, audioEl)
        notify()
    }

    function unregisterEl(token, audioEl) {
        if (token == null || !audioEl) return
        if (tokenElements.get(token) === audioEl) {
            tokenElements.delete(token)
            notify()
        }
    }

    /**
     * Resolve the canonical audio element for a token. Prefers the
     * playing element when one exists; falls back to whichever element
     * was last registered for that token (e.g. the AudioCardVisual on
     * the asset detail drawer that hasn't been clicked yet).
     */
    function getElementByToken(token) {
        if (token == null) return null
        if (currentToken === token && currentEl) return currentEl
        return tokenElements.get(token) ?? null
    }

    return {
        attach,
        play,
        pause,
        subscribe,
        getCurrentToken,
        getCurrentEl,
        getAnalyserData,
        getAnalyser,
        registerEl,
        unregisterEl,
        getElementByToken,
    }
}

export function getAudioRegistry() {
    if (!singleton) {
        singleton = createRegistry()
    }
    return singleton
}
