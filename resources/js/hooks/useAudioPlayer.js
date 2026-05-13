import { useCallback, useEffect, useRef, useState } from 'react'
import {
    cdnUrlForDisplayWithoutQuery,
    classifyAudioPlaybackFailure,
    getCdnPreviewFailureCopy,
    inferAudioDeliveryVariant,
    isProbablyCloudFrontSignedUrl,
    logCdnMediaDiagnostics,
    probeCdnAssetAvailability,
} from '../utils/cdnAssetLoadDiagnostics'
import { getAudioRegistry } from '../utils/audioPlayerRegistry'

/**
 * Hook that turns any component into a self-contained audio player wired
 * through the shared registry. Used by AudioCardVisual (grid),
 * AudioDrawerVisual (right-rail quick view), and AudioLightboxPlayer
 * (modal). Same hook → identical behavior + identical analyser frames.
 *
 * Two roles supported by the same hook:
 *   1. Owner: pass `audioRef` to a real `<audio>` element you render.
 *      The hook will register the element with the shared registry so
 *      sibling instances (role 2) can observe it.
 *   2. Observer: don't render an `<audio>` element. The hook resolves
 *      the registry's element for this token and binds metadata /
 *      time / play / seek to it. This is how the lightbox transport
 *      drives the AudioCardVisual's audio element without needing its
 *      own `<audio>` (which would burn bandwidth and desync state).
 *
 * Returns:
 *   audioRef               attach to an `<audio>` element (owner role)
 *   isPlaying              boolean (true only when this token owns playback)
 *   isLoading              true while the element is buffering after play()
 *   currentTime / duration numbers in seconds (0 when unknown)
 *   buffered               { start: number, end: number } of the latest
 *                          buffered range, in seconds, or null
 *   playbackRate           current playback rate (1 = normal)
 *   toggle()               play if not owner, pause if owner
 *   stop()                 pause + release ownership
 *   seekTo(seconds)        absolute seek
 *   skip(deltaSeconds)     relative seek (+/- against currentTime)
 *   setPlaybackRate(rate)  set playback rate on the live element
 *   getFrequencyData()     Uint8Array of length frequencyBinCount, sampled
 *                          on demand — only meaningful while isPlaying
 *
 * CDN / play diagnostics are logged to the browser console only
 * (see {@link logCdnMediaDiagnostics} in cdnAssetLoadDiagnostics.js).
 */
export default function useAudioPlayer({ token, src, asset = null }) {
    const audioRef = useRef(null)
    const srcRef = useRef(src)
    srcRef.current = src
    const assetRef = useRef(asset)
    assetRef.current = asset

    const [isPlaying, setIsPlaying] = useState(false)
    const [isLoading, setIsLoading] = useState(false)
    const [currentTime, setCurrentTime] = useState(0)
    const [duration, setDuration] = useState(0)
    const [buffered, setBuffered] = useState(null)
    const [playbackRate, setPlaybackRateState] = useState(1)

    const logAudioCdn = useCallback((reason, { displayCategory, probe, mediaErrorCode } = {}) {
        const a = assetRef.current
        const url = srcRef.current
        const hint =
            displayCategory != null ? getCdnPreviewFailureCopy(displayCategory, probe?.httpStatus) : null
        logCdnMediaDiagnostics('audio', {
            reason: reason || null,
            asset_id: a?.id ?? token,
            variant: inferAudioDeliveryVariant(a || {}, url || ''),
            displayCategory: displayCategory ?? null,
            operator_hint_primary: hint?.primary ?? null,
            operator_hint_secondary: hint?.secondary ?? null,
            cdn_host: probe?.modelHost ?? null,
            http_status: probe?.httpStatus ?? null,
            media_error_code: mediaErrorCode ?? null,
            page_origin: probe?.pageOrigin ?? null,
            url_delivery_guess: url && isProbablyCloudFrontSignedUrl(url) ? 'signed_url' : 'plain_cdn_expect_cookies',
            cdn_path: url ? cdnUrlForDisplayWithoutQuery(url, probe?.pageOrigin) : null,
        })
    }, [token])

    // Resolve the audio element this hook should observe right now —
    // either the one we render ourselves (owner role) or whatever the
    // registry has registered against our token (observer role).
    const resolveEl = useCallback(() => {
        if (audioRef.current) return audioRef.current
        return getAudioRegistry().getElementByToken(token)
    }, [token])

    // Owner role: register our local element with the registry on mount
    // so the lightbox / drawer can find it.
    useEffect(() => {
        const el = audioRef.current
        if (!el) return undefined
        const reg = getAudioRegistry()
        reg.registerEl(token, el)
        return () => reg.unregisterEl(token, el)
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [token, audioRef.current])

    // Track ownership via the shared registry — when another card starts
    // playing, our `isPlaying` flips false automatically. Also re-syncs
    // observed state whenever the registry's owner changes.
    useEffect(() => {
        const reg = getAudioRegistry()
        const update = () => {
            const owned = reg.getCurrentToken() === token
            const el = resolveEl()
            setIsPlaying(owned && !!el && !el.paused)
        }
        update()
        return reg.subscribe(update)
    }, [token, resolveEl])

    // Bind transport events to the *currently observed* element. When the
    // registry's element changes (e.g. another component's audio element
    // takes over the token), tear down old listeners and bind to the new
    // one — and pull initial state from it so duration / currentTime
    // show up immediately rather than waiting for the next event.
    useEffect(() => {
        const reg = getAudioRegistry()
        let bound = null

        const onTime = (e) => setCurrentTime(e.currentTarget.currentTime || 0)
        const onMeta = (e) => {
            const el = e.currentTarget
            setDuration(Number.isFinite(el.duration) ? el.duration : 0)
        }
        const onProgress = (e) => {
            const el = e.currentTarget
            try {
                const len = el.buffered?.length ?? 0
                if (len > 0) {
                    setBuffered({ start: el.buffered.start(0), end: el.buffered.end(len - 1) })
                }
            } catch (_) {}
        }
        const onPlaying = () => {
            setIsPlaying(true)
            setIsLoading(false)
        }
        const onPause = () => setIsPlaying(false)
        const onWaiting = () => setIsLoading(true)
        const onCanPlay = () => setIsLoading(false)
        const onRateChange = (e) => setPlaybackRateState(e.currentTarget.playbackRate || 1)
        const onEnded = () => {
            setIsPlaying(false)
            getAudioRegistry().pause(token)
        }

        const onAudioError = (e) => {
            const el = e.currentTarget
            if (!el) return
            const url = el.currentSrc || el.src || srcRef.current
            const code = el.error?.code ?? null
            ;(async () => {
                try {
                    const probe = await probeCdnAssetAvailability(url, {})
                    if (probe === null) {
                        return
                    }
                    const displayCategory = classifyAudioPlaybackFailure(probe, code)
                    logAudioCdn('audio_element_error', { displayCategory, probe, mediaErrorCode: code })
                } catch {
                    logAudioCdn('audio_element_error_probe_failed', {
                        displayCategory: 'cors_or_unknown',
                        probe: null,
                        mediaErrorCode: code,
                    })
                }
            })()
        }

        const bind = (el) => {
            if (bound === el) return
            if (bound) {
                bound.removeEventListener('timeupdate', onTime)
                bound.removeEventListener('loadedmetadata', onMeta)
                bound.removeEventListener('durationchange', onMeta)
                bound.removeEventListener('progress', onProgress)
                bound.removeEventListener('playing', onPlaying)
                bound.removeEventListener('pause', onPause)
                bound.removeEventListener('waiting', onWaiting)
                bound.removeEventListener('canplay', onCanPlay)
                bound.removeEventListener('ratechange', onRateChange)
                bound.removeEventListener('ended', onEnded)
                bound.removeEventListener('error', onAudioError)
            }
            bound = el
            if (!el) return
            el.addEventListener('timeupdate', onTime)
            el.addEventListener('loadedmetadata', onMeta)
            el.addEventListener('durationchange', onMeta)
            el.addEventListener('progress', onProgress)
            el.addEventListener('playing', onPlaying)
            el.addEventListener('pause', onPause)
            el.addEventListener('waiting', onWaiting)
            el.addEventListener('canplay', onCanPlay)
            el.addEventListener('ratechange', onRateChange)
            el.addEventListener('ended', onEnded)
            el.addEventListener('error', onAudioError)
            // Pull initial state — the element may already have metadata
            // loaded by the time we're binding (e.g. when the lightbox
            // mounts after the grid card has had time to preload).
            setCurrentTime(el.currentTime || 0)
            setDuration(Number.isFinite(el.duration) ? el.duration : 0)
            setPlaybackRateState(el.playbackRate || 1)
            setIsPlaying(reg.getCurrentToken() === token && !el.paused)
            try {
                const len = el.buffered?.length ?? 0
                if (len > 0) setBuffered({ start: el.buffered.start(0), end: el.buffered.end(len - 1) })
            } catch (_) {}
        }

        bind(resolveEl())
        const unsub = reg.subscribe(() => bind(resolveEl()))
        // Also re-bind whenever audioRef is populated post-mount (the ref
        // callback assignment can fire after this effect's first run).
        const tickId = setInterval(() => {
            const next = resolveEl()
            if (next !== bound) bind(next)
        }, 250)

        return () => {
            unsub()
            clearInterval(tickId)
            bind(null)
        }
    }, [token, resolveEl, logAudioCdn])

    // If the asset src changes (rare in practice, e.g. reupload), reset state.
    useEffect(() => {
        const el = audioRef.current
        if (!el) return
        if (el.src && el.src !== src) {
            try {
                el.pause()
            } catch (_) {}
            setCurrentTime(0)
            setDuration(0)
        }
    }, [src])

    // Cleanly release the registry slot if we unmount mid-playback so a
    // remounted parent doesn't double-play.
    useEffect(() => {
        return () => {
            const reg = getAudioRegistry()
            if (reg.getCurrentToken() === token) reg.pause(token)
        }
    }, [token])

    const toggle = useCallback(async () => {
        const el = resolveEl()
        if (!el) return
        const reg = getAudioRegistry()
        if (reg.getCurrentToken() === token && !el.paused) {
            el.pause()
            reg.pause(token)
            return
        }
        setIsLoading(true)
        const res = await reg.play(token, el)
        if (res && res.ok === true) {
            return
        }
        setIsLoading(false)
        if (res?.failureKind === 'autoplay') {
            logAudioCdn('play_autoplay_blocked', { displayCategory: 'autoplay', probe: null, mediaErrorCode: null })
            return
        }
        if (res?.failureKind === 'playback' && srcRef.current) {
            try {
                const probe = await probeCdnAssetAvailability(srcRef.current, {})
                if (probe === null) {
                    return
                }
                const displayCategory = classifyAudioPlaybackFailure(probe, null)
                logAudioCdn('play_rejected', { displayCategory, probe, mediaErrorCode: null })
            } catch {
                logAudioCdn('play_rejected_probe_failed', {
                    displayCategory: 'cors_or_unknown',
                    probe: null,
                    mediaErrorCode: null,
                })
            }
        }
    }, [token, resolveEl, logAudioCdn])

    const stop = useCallback(() => {
        const reg = getAudioRegistry()
        const el = resolveEl()
        try {
            el?.pause()
        } catch (_) {}
        reg.pause(token)
    }, [token, resolveEl])

    const seekTo = useCallback(
        (seconds) => {
            const el = resolveEl()
            if (!el || !Number.isFinite(seconds)) return
            const max = Number.isFinite(el.duration) ? el.duration : seconds
            const target = Math.max(0, Math.min(seconds, max))
            try {
                el.currentTime = target
                setCurrentTime(target)
            } catch (_) {}
        },
        [resolveEl],
    )

    const skip = useCallback(
        (delta) => {
            const el = resolveEl()
            if (!el || !Number.isFinite(delta)) return
            seekTo((el.currentTime || 0) + delta)
        },
        [resolveEl, seekTo],
    )

    const setPlaybackRate = useCallback(
        (rate) => {
            const el = resolveEl()
            if (!el || !Number.isFinite(rate) || rate <= 0) return
            try {
                el.playbackRate = rate
                setPlaybackRateState(rate)
            } catch (_) {}
        },
        [resolveEl],
    )

    const getFrequencyData = useCallback(() => getAudioRegistry().getAnalyserData(), [])

    return {
        audioRef,
        isPlaying,
        isLoading,
        currentTime,
        duration,
        buffered,
        playbackRate,
        toggle,
        stop,
        seekTo,
        skip,
        setPlaybackRate,
        getFrequencyData,
    }
}
