import React, { useMemo, useState } from 'react'

/**
 * Card-sized visual for audio assets.
 *
 * Three layered states, in order:
 *   1. Always: brand-tinted gradient + a deterministic synthetic waveform
 *      derived from the asset id. Each asset gets a unique-looking
 *      "fingerprint" of mirrored bars — so even before the FFmpeg
 *      waveform PNG is rendered, the tile reads as audio (never as a
 *      broken image).
 *   2. When `asset.audio_waveform_url` is present (set by the
 *      GenerateAudioWaveformJob → AudioWaveformService pipeline) the
 *      real waveform crossfades in over the synthetic bars while
 *      keeping the gradient backdrop + chrome.
 *   3. Chrome: small "AUDIO" pill top-left, AI status pill top-right
 *      while processing, play triangle bottom-left, duration bottom-
 *      right. The chrome gives the card a strong type identity (a la
 *      Spotify / SoundCloud) so audio assets are obvious in a mixed
 *      asset grid.
 *
 * IMPORTANT: this component never renders an <img> with a missing src
 * (the previous version fell back to `asset.thumbnail_url`, which
 * landed broken on audio assets). The real waveform <img> only mounts
 * when its url is non-empty.
 */
function formatDuration(seconds) {
    if (!Number.isFinite(seconds) || seconds <= 0) return null
    const total = Math.round(seconds)
    const m = Math.floor(total / 60)
    const s = total % 60
    if (m >= 60) {
        const h = Math.floor(m / 60)
        const mm = m % 60
        return `${h}:${String(mm).padStart(2, '0')}:${String(s).padStart(2, '0')}`
    }
    return `${m}:${String(s).padStart(2, '0')}`
}

/**
 * djb2-ish hash → integer. Stable across reloads / users / browsers,
 * so the same asset always gets the same waveform shape.
 */
function hashSeed(str) {
    let h = 5381
    if (!str) return 0
    for (let i = 0; i < str.length; i++) {
        h = ((h << 5) + h) + str.charCodeAt(i)
        h |= 0
    }
    return Math.abs(h)
}

/**
 * Generate `count` bar amplitudes in [0.18, 1.0] from a seed.
 * Uses an LCG so bars are pseudo-random but reproducible per asset.
 */
function syntheticBars(seed, count = 64) {
    let state = seed || 1
    const bars = new Array(count)
    for (let i = 0; i < count; i++) {
        state = (state * 1103515245 + 12345) & 0x7fffffff
        const r = (state / 0x7fffffff)
        // Bias toward middle-tall bars so the silhouette reads as a song,
        // with occasional taller "transient" peaks like real audio.
        const base = 0.25 + r * 0.55
        const peak = ((state >> 7) & 0x1f) === 0 ? 0.95 : base
        bars[i] = Math.min(1, peak)
    }
    return bars
}

/**
 * Color helpers — derive a slightly darker companion to the brand color
 * for the gradient floor. Falls back to indigo if no brand color set.
 */
function hexToRgb(hex) {
    if (!hex || typeof hex !== 'string') return null
    const m = hex.replace('#', '')
    if (m.length !== 6) return null
    const n = parseInt(m, 16)
    if (Number.isNaN(n)) return null
    return { r: (n >> 16) & 0xff, g: (n >> 8) & 0xff, b: n & 0xff }
}

function rgba(rgb, a) {
    if (!rgb) return `rgba(99,102,241,${a})`
    return `rgba(${rgb.r},${rgb.g},${rgb.b},${a})`
}

export default function AudioCardVisual({ asset, primaryColor = '#6366f1', className = '' }) {
    const audioMeta = asset?.metadata?.audio || {}
    const realWaveformUrl =
        (typeof asset?.audio_waveform_url === 'string' && asset.audio_waveform_url) || null
    const duration = useMemo(() => formatDuration(audioMeta?.duration_seconds), [audioMeta?.duration_seconds])
    const aiStatus = audioMeta?.ai_status

    const seed = useMemo(() => hashSeed(asset?.id || asset?.original_filename || 'audio'), [asset?.id, asset?.original_filename])
    const bars = useMemo(() => syntheticBars(seed, 64), [seed])

    const rgb = useMemo(() => hexToRgb(primaryColor), [primaryColor])
    const gradient = useMemo(() => {
        const a = rgba(rgb, 0.18)
        const b = rgba(rgb, 0.42)
        return `linear-gradient(135deg, ${a} 0%, ${b} 100%)`
    }, [rgb])

    const [waveformLoaded, setWaveformLoaded] = useState(false)

    return (
        <div
            className={`relative flex h-full w-full items-center justify-center overflow-hidden ${className}`}
            style={{ background: gradient }}
            aria-label={asset?.title || asset?.original_filename || 'Audio'}
        >
            {/* Layer 1 — synthetic waveform (always rendered, fades out under the real one). */}
            <svg
                viewBox="0 0 100 50"
                preserveAspectRatio="none"
                className="absolute inset-x-3 inset-y-0 h-full"
                style={{
                    width: 'calc(100% - 1.5rem)',
                    opacity: waveformLoaded ? 0.18 : 0.85,
                    transition: 'opacity 320ms ease',
                }}
                aria-hidden="true"
            >
                {bars.map((h, i) => {
                    const w = 100 / bars.length
                    const x = i * w
                    const barW = Math.max(0.6, w * 0.55)
                    const half = h * 22 // bar reaches +/-22 around centerline (=44 of 50 total)
                    return (
                        <rect
                            key={i}
                            x={x + (w - barW) / 2}
                            y={25 - half}
                            width={barW}
                            height={half * 2}
                            rx={barW * 0.45}
                            fill={primaryColor}
                            opacity={0.55 + (i % 5) * 0.08}
                        />
                    )
                })}
            </svg>

            {/* Layer 2 — real FFmpeg waveform PNG. Mounted only when a url exists. */}
            {realWaveformUrl ? (
                <img
                    src={realWaveformUrl}
                    alt=""
                    className="absolute inset-0 h-full w-full select-none object-cover transition-opacity duration-300"
                    style={{ opacity: waveformLoaded ? 0.95 : 0 }}
                    loading="lazy"
                    draggable={false}
                    onLoad={() => setWaveformLoaded(true)}
                    onError={() => setWaveformLoaded(false)}
                />
            ) : null}

            {/* Layer 3 — chrome */}
            <div className="pointer-events-none absolute left-2 top-2 flex items-center gap-1 rounded-full bg-white/85 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-700 shadow-sm backdrop-blur-sm dark:bg-slate-900/70 dark:text-slate-100">
                <svg viewBox="0 0 24 24" className="h-3 w-3" fill="currentColor" aria-hidden="true">
                    <path d="M12 3v10.55A4 4 0 1 0 14 17V7h4V3h-6z" />
                </svg>
                Audio
            </div>

            {(aiStatus === 'queued' || aiStatus === 'processing') && (
                <div className="pointer-events-none absolute right-2 top-2 flex items-center gap-1 rounded-full bg-indigo-600/90 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white shadow-sm">
                    <span className="relative flex h-1.5 w-1.5">
                        <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-white opacity-75" />
                        <span className="relative inline-flex h-1.5 w-1.5 rounded-full bg-white" />
                    </span>
                    AI
                </div>
            )}

            <div className="pointer-events-none absolute bottom-2 left-2 flex h-7 w-7 items-center justify-center rounded-full bg-white/90 text-slate-800 shadow-md backdrop-blur-sm transition-transform duration-200 group-hover:scale-110 dark:bg-slate-900/80 dark:text-slate-100">
                <svg viewBox="0 0 24 24" className="h-3.5 w-3.5 translate-x-[1px]" fill="currentColor" aria-hidden="true">
                    <path d="M8 5v14l11-7z" />
                </svg>
            </div>

            {duration && (
                <span className="pointer-events-none absolute bottom-2 right-2 rounded-md bg-black/70 px-2 py-0.5 font-mono text-[11px] font-medium text-white shadow-sm backdrop-blur-sm">
                    {duration}
                </span>
            )}
        </div>
    )
}
