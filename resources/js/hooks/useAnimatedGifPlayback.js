import { useState, useEffect, useMemo, useCallback } from 'react'
import { checkUrlIsAnimatedGif } from '../utils/gifAnimationDetection'

export function isGifRasterAsset(asset) {
    if (!asset) return false
    const m = (asset.mime_type || '').toLowerCase()
    if (m === 'image/gif') return true
    const ext = (asset.file_extension || '').toLowerCase()
    if (ext === 'gif') return true
    const fn = (asset.original_filename || '').toLowerCase()
    return fn.endsWith('.gif')
}

/**
 * Drawer / lightbox: static pipeline thumbnail as poster, authenticated original GIF for playback.
 *
 * @param {object} opts
 * @param {boolean} opts.enabled
 * @param {object|null|undefined} opts.asset
 * @param {string|null|undefined} opts.posterUrl — visible thumbnail (usually static derivative)
 * @param {RequestInit} [opts.fetchInit]
 */
export function useAnimatedGifPlayback({ enabled, asset, posterUrl, fetchInit = {} }) {
    const animationUrl = useMemo(() => {
        if (!isGifRasterAsset(asset)) return null
        const o = typeof asset?.original === 'string' ? asset.original.trim() : ''
        return o || null
    }, [asset])

    const [animState, setAnimState] = useState('unknown')
    const [playing, setPlaying] = useState(false)

    const poster = typeof posterUrl === 'string' ? posterUrl.trim() : ''

    useEffect(() => {
        setPlaying(false)
        setAnimState('unknown')
    }, [asset?.id, poster, animationUrl])

    useEffect(() => {
        if (!enabled || !animationUrl || !poster) {
            setAnimState('no')
            return
        }
        if (!isGifRasterAsset(asset)) {
            setAnimState('no')
            return
        }
        if (poster === animationUrl) {
            setAnimState('no')
            return
        }
        let cancelled = false
        checkUrlIsAnimatedGif(animationUrl, fetchInit).then((ok) => {
            if (cancelled) return
            setAnimState(ok ? 'yes' : 'no')
        })
        return () => {
            cancelled = true
        }
    }, [enabled, asset?.id, asset?.mime_type, asset?.original_filename, asset?.file_extension, asset?.original, animationUrl, poster])

    const showPlayback = enabled && animState === 'yes' && Boolean(poster) && Boolean(animationUrl)

    const displaySrc = showPlayback ? (playing ? animationUrl : poster) : posterUrl

    const toggle = useCallback(() => {
        if (!showPlayback) return
        setPlaying((p) => !p)
    }, [showPlayback])

    return { showPlayback, playing, toggle, displaySrc, animState, animationUrl }
}
