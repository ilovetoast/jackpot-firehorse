import React, { useEffect, useId, useMemo, useRef, useState } from 'react'
import useAudioPlayer from '../../hooks/useAudioPlayer'
import { audioCardIntrinsicMinHeightPx } from '../../utils/audioCardSizing'
import { isLiveAudioAnalyserEnabled } from '../../utils/audioPlaybackFlags'

/**
 * Card-sized audio visual.
 *
 * Visual language (matches the brand palette and the video player chrome):
 *   - Dark brand-tinted gradient background (deep base, never light gray).
 *   - Organic waveform bars in the brand accent color — varying widths
 *     (thin / thick / thin / fat) and asymmetric amplitudes so the
 *     silhouette reads as a living waveform, not a metronome.
 *   - When idle: deterministic synthetic bars seeded from the asset id
 *     (so each track has a unique, stable fingerprint).
 *   - When playing: bars are driven by a shared Web Audio AnalyserNode
 *     so they react to actual audio levels.
 *   - Play / pause control: bottom-left, frosted black/40 blur circle
 *     matching the existing video overlay (just smaller).
 *   - Duration overlay: bottom-right when known.
 *   - No "AUDIO" pill — type identity comes from the visual itself.
 *
 * Design language: an editor-style printed waveform (capsule bars with
 * dramatic height variation), set on the brand-tinted dark gradient. We
 * intentionally do NOT draw any chrome over the bars — no bezel ring, no
 * reflection highlight, no reel separators, no curved-viewport mask. Each
 * one of those, on iteration, ended up reading as "this is a framed bar
 * chart" rather than as a free-floating waveform; the reference designs
 * the agency stakeholders pointed at have none of that ornamentation.
 *
 * Jackpot brand cues (subtle, only show up during user activity):
 *   1. Soft-cornered bars: each bar uses rx = ry = w * 0.28 — clearly
 *      rounded but NOT a full capsule. Full pill caps were reviewed as
 *      "lozenge-y", and square corners read as graph paper; this is the
 *      middle ground. Height is the dominant rhythm cue. Opacity is
 *      uniform at idle (every bar full). The instant playback engages
 *      — playing, buffering, or paused with progress > 0 — bars not yet
 *      reached drop hard to 0.28 opacity, and bars at-or-behind the
 *      play-head pop back to full 1.0. The result is an unmistakable
 *      progress-fill wipe driven entirely by opacity contrast, no
 *      separate progress bar needed.
 *   2. Pay-line: 1px horizontal accent hairline at the vertical center.
 *      INVISIBLE when idle, brightens during playback, flashes briefly
 *      on loud peaks. This is the "win line" beat without saying so —
 *      the only persistent slot cue, and it only shows up on activity.
 *   3. Spin-on-load: when audio is buffering, bars + pay-line get a
 *      vertical scroll-blur animation that mimics a reel mid-spin,
 *      then snap to crisp on play. Strongest slot cue — only visible
 *      during a transient state, so it never feels heavy.
 *   4. Live-driven amplitudes: when playing, the AnalyserNode output
 *      replaces the synthetic envelope so the bars become a literal
 *      VU meter for what's coming out of the speakers.
 *
 * The component is fully self-contained: clicking the play button starts
 * actual audio playback through the shared registry, so it works in the
 * grid, in the asset drawer, and in the lightbox identically.
 */
function hashSeed(str) {
    let h = 5381
    if (!str) return 1
    for (let i = 0; i < str.length; i++) {
        h = ((h << 5) + h) + str.charCodeAt(i)
        h |= 0
    }
    return Math.abs(h) || 1
}

/**
 * Build organic-feeling synthetic bar data: variable widths, asymmetric
 * top/bottom heights, occasional taller "transients" — modeled after
 * real audio waveform PNGs (and the reference designs the user shared).
 *
 * @returns Array<{ x: number, w: number, top: number, bot: number }>
 *   x, w, top, bot are all in % units (0..100) of the SVG viewBox.
 */
function syntheticBars(seed, count = 56) {
    let s = seed
    const rng = () => {
        s = (s * 1103515245 + 12345) & 0x7fffffff
        return s / 0x7fffffff
    }
    const bars = []
    let x = 1
    const totalWidth = 98
    // Tight packing: small gap factor brings the bars in close enough that
    // the silhouette reads as a continuous waveform envelope, matching the
    // printed-waveform reference rather than a sparser bar chart.
    const GAP_WEIGHT = 0.22
    const weights = []
    let weightSum = 0
    for (let i = 0; i < count; i++) {
        // Width variation is intentionally MILD (was 0.6–2.6vb, now 0.8–
        // 1.4vb). Heights should drive the silhouette — fat outlier bars
        // make the visual feel like a chart, not a waveform. Refs
        // consistently show near-uniform widths with dramatic height swing.
        const r = rng()
        const w = r < 0.85 ? 0.8 + rng() * 0.3 : 1.1 + rng() * 0.3
        weights.push(w)
        weightSum += w + GAP_WEIGHT
    }
    const scale = totalWidth / weightSum
    for (let i = 0; i < count; i++) {
        const w = weights[i] * scale
        const gap = GAP_WEIGHT * scale
        // Height bias: middle of card is louder, edges quieter — mimics
        // the natural envelope of a song that opens, peaks, and tails.
        // Wider amplitude range now (0.18 floor vs 0.10 before, 1.0 ceiling
        // vs 0.98) so peaks really tower and valleys really dip — the
        // dynamic range itself is the visual story.
        const center = (i / (count - 1)) * 2 - 1 // [-1, 1]
        const envelope = Math.cos(center * 0.9) * 0.55 + 0.45
        const baseAmp = (0.28 + rng() * 0.6) * envelope
        const peakRoll = ((s >> 8) & 0x1f) === 0 ? 1.0 : baseAmp
        // Symmetric: top and bottom heights match. The reference shows a
        // mirrored top/bottom waveform, not a lopsided one.
        const amp = Math.min(1.0, Math.max(0.18, peakRoll))
        bars.push({ x, w, top: amp, bot: amp })
        x += w + gap
    }
    return bars
}

/**
 * Map AnalyserNode frequency data (Uint8Array length = analyser.frequencyBinCount)
 * onto our bar-count grid, returning normalized [0..1] amplitudes.
 */
function sampleAnalyser(buffer, count) {
    if (!buffer || buffer.length === 0) return null
    const out = new Array(count)
    const step = buffer.length / count
    for (let i = 0; i < count; i++) {
        // Average the bin slice for smoother bars (vs. raw indexing).
        const start = Math.floor(i * step)
        const end = Math.max(start + 1, Math.floor((i + 1) * step))
        let sum = 0
        for (let j = start; j < end; j++) sum += buffer[j]
        const avg = sum / Math.max(1, end - start)
        out[i] = Math.min(1, (avg / 255) ** 0.85)
    }
    return out
}

/**
 * Synthetic "bouncing" levels used when the live AnalyserNode is unavailable
 * (no Web Audio route: default config, or cross-origin audio without CORS when
 * live analyser is enabled). Several sine waves at different frequencies + per-bar phase offsets give
 * each bar an independent, organic-looking bounce instead of every bar
 * pulsing in lock-step. Modulated by a slow envelope so the whole waveform
 * occasionally swells and dips, mimicking a real song's dynamics.
 *
 * Important: amplitudes are scaled against the bar's idle envelope so taller
 * bars stay taller and shorter bars stay shorter — the silhouette still reads
 * as the track's signature waveform, just animated.
 *
 * @param {number} t          performance.now() in ms
 * @param {Array<{top:number, bot:number}>} bars  idle bar envelope
 * @returns {Array<number>}   normalized [0..1] amplitudes per bar
 */
function syntheticBounceLevels(t, bars) {
    if (!bars || bars.length === 0) return null
    const tt = t / 1000
    // Slow whole-card "breathing" — 0.55..1.0 over ~3s. Keeps the floor up
    // so even quiet beats still register a visible bounce.
    const breathing = 0.55 + 0.45 * (0.5 + 0.5 * Math.sin(tt * 2.05))
    const out = new Array(bars.length)
    for (let i = 0; i < bars.length; i++) {
        // Per-bar phase derived from index — gives a traveling-wave feel
        // rather than a metronome. Three layered sines at different
        // frequencies prevent the motion from looking periodic.
        const phase = i * 0.37
        const a = Math.sin(tt * 7.2 + phase) * 0.5 + 0.5
        const b = Math.sin(tt * 11.7 + phase * 1.6 + 1.3) * 0.5 + 0.5
        const c = Math.sin(tt * 4.1 + phase * 0.5 + 2.1) * 0.5 + 0.5
        const mix = (a * 0.5 + b * 0.3 + c * 0.2)
        // Anchor against the idle envelope so the silhouette is preserved:
        // tall bars bounce taller, short bars bounce shorter.
        const idleAmp = (bars[i].top + bars[i].bot) / 2
        const lifted = idleAmp * (0.55 + 0.55 * mix) * breathing
        out[i] = Math.min(1, Math.max(0.12, lifted))
    }
    return out
}

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

function darkenHex(hex, factor = 0.35) {
    if (typeof hex !== 'string' || !/^#?[0-9a-f]{6}$/i.test(hex.replace('#', ''))) return '#1f2937'
    const n = parseInt(hex.replace('#', ''), 16)
    const r = Math.round(((n >> 16) & 0xff) * factor)
    const g = Math.round(((n >> 8) & 0xff) * factor)
    const b = Math.round((n & 0xff) * factor)
    return `rgb(${r}, ${g}, ${b})`
}

const VIEW_W = 100
const VIEW_H = 56
const CENTER_Y = VIEW_H / 2

function PlayIcon({ className = 'h-4 w-4' }) {
    return (
        <svg viewBox="0 0 24 24" className={className} fill="currentColor" aria-hidden="true">
            <path d="M8 5v14l11-7z" />
        </svg>
    )
}

function PauseIcon({ className = 'h-4 w-4' }) {
    return (
        <svg viewBox="0 0 24 24" className={className} fill="currentColor" aria-hidden="true">
            <rect x="6" y="5" width="4" height="14" rx="1" />
            <rect x="14" y="5" width="4" height="14" rx="1" />
        </svg>
    )
}

function LoadingSpinner({ className = 'h-4 w-4' }) {
    return (
        <svg viewBox="0 0 24 24" className={`${className} animate-spin`} fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.3" strokeWidth="3" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
        </svg>
    )
}

export default function AudioCardVisual({
    asset,
    primaryColor = '#f97316',
    className = '',
    /**
     * Size variant — `card` (grid tile, default), `drawer` (right-rail),
     * `lightbox` (modal). Affects bar density + chrome scale only; the
     * underlying playback engine and bar logic are identical.
     */
    size = 'card',
}) {
    const audioMeta = asset?.metadata?.audio || {}
    const duration = useMemo(() => formatDuration(audioMeta?.duration_seconds), [audioMeta?.duration_seconds])
    const aiStatus = audioMeta?.ai_status

    const src = useMemo(
        () =>
            (typeof asset?.audio_playback_url === 'string' && asset.audio_playback_url) ||
            (typeof asset?.original_url === 'string' && asset.original_url) ||
            (typeof asset?.preview_url === 'string' && asset.preview_url) ||
            '',
        [asset?.audio_playback_url, asset?.original_url, asset?.preview_url],
    )

    const token = asset?.id ?? src
    const { audioRef, isPlaying, isLoading, currentTime, duration: liveDuration, toggle, getFrequencyData } =
        useAudioPlayer({ token, src, asset })

    const seed = useMemo(() => hashSeed(asset?.id || asset?.original_filename || 'audio'), [asset?.id, asset?.original_filename])
    const barCount = size === 'lightbox' ? 80 : size === 'drawer' ? 60 : 56
    const baseBars = useMemo(() => syntheticBars(seed, barCount), [seed, barCount])

    // Stable per-instance id so SVG mask/gradient ids never collide between
    // multiple AudioCardVisual mounts on the same page (grid + drawer +
    // lightbox can all be live at once).
    const reactId = useId()
    const svgId = useMemo(() => reactId.replace(/[^a-zA-Z0-9_-]/g, ''), [reactId])

    // Live bar levels driven by the shared analyser. We RAF only while playing.
    const [levels, setLevels] = useState(null)
    // Rolling peak energy for the pay-line "jackpot" flash — kept as a ref
    // so we don't trigger React re-renders on every animation frame.
    const peakRef = useRef(0)
    const [payLinePulse, setPayLinePulse] = useState(0)
    const rafRef = useRef(null)
    useEffect(() => {
        if (!isPlaying) {
            if (rafRef.current) cancelAnimationFrame(rafRef.current)
            rafRef.current = null
            setLevels(null)
            peakRef.current = 0
            setPayLinePulse(0)
            return undefined
        }
        let lastPulseAt = 0
        const tick = () => {
            const now = performance.now()
            // Prefer real frequency data when the analyser is wired in
            // (config: audio.live_analyser_enabled). When it isn't, Web Audio
            // is not routing the element — we still animate bars with a synthetic
            // envelope so the card feels alive during playback. When the
            // analyser is on, crossOrigin + CDN issues are logged from useAudioPlayer
            // (DevTools only; not shown in the product UI).
            const data = getFrequencyData()
            const sampled = sampleAnalyser(data, barCount) || syntheticBounceLevels(now, baseBars)
            if (sampled) {
                setLevels(sampled)
                // Mean energy across the spectrum — when it spikes above the
                // rolling baseline, briefly brighten the pay-line. This is
                // the "jackpot win" cue: the win-line glows on loud beats
                // without ever feeling literal.
                let sum = 0
                for (let i = 0; i < sampled.length; i++) sum += sampled[i]
                const mean = sum / sampled.length
                peakRef.current = peakRef.current * 0.92 + mean * 0.08
                if (mean > peakRef.current * 1.4 && now - lastPulseAt > 120) {
                    lastPulseAt = now
                    setPayLinePulse(1)
                    setTimeout(() => setPayLinePulse(0), 180)
                }
            }
            rafRef.current = requestAnimationFrame(tick)
        }
        rafRef.current = requestAnimationFrame(tick)
        return () => {
            if (rafRef.current) cancelAnimationFrame(rafRef.current)
            rafRef.current = null
        }
    }, [isPlaying, barCount, baseBars, getFrequencyData])

    // Progress: prefer live duration from <audio>, fall back to FFprobe metadata.
    const effectiveDuration = liveDuration || audioMeta?.duration_seconds || 0
    const progress = effectiveDuration > 0 ? Math.min(1, currentTime / effectiveDuration) : 0

    const bgGradient = useMemo(() => {
        const dark = darkenHex(primaryColor, 0.32)
        const darker = darkenHex(primaryColor, 0.14)
        return `linear-gradient(135deg, ${dark} 0%, ${darker} 100%)`
    }, [primaryColor])

    const playButtonScale = size === 'lightbox' ? 'h-12 w-12' : size === 'drawer' ? 'h-10 w-10' : 'h-9 w-9'
    const playIconScale = size === 'lightbox' ? 'h-5 w-5' : 'h-4 w-4'

    const handlePlayClick = (e) => {
        // Stop the click from also opening the asset drawer / lightbox.
        e.preventDefault()
        e.stopPropagation()
        toggle()
    }

    // Pay-line opacity: hidden when idle (no chrome on a static card),
    // appears during playback, flashes briefly on loud peaks. Visibility
    // is gated on activity so the idle state stays as clean as the
    // reference designs we lifted the silhouette from.
    const payLineOpacity = isLoading
        ? 0.18
        : isPlaying
          ? 0.32 + 0.45 * payLinePulse
          : 0

    // Per-size intrinsic floor: when the parent only specifies a percentage
    // height (e.g. masonry `min-height` only), `h-full` here resolves to 0
    // and the gradient + waveform never paint. We fall back to a sensible
    // pixel floor so the component is always self-painting regardless of
    // parent layout. See {@link audioCardIntrinsicMinHeightPx} for the
    // size-specific values + rationale.
    const intrinsicMinHeight = audioCardIntrinsicMinHeightPx(size)

    return (
        <div
            className={`relative flex h-full w-full items-center justify-center overflow-hidden ${className}`}
            style={{ background: bgGradient, minHeight: intrinsicMinHeight }}
            aria-label={asset?.title || asset?.original_filename || 'Audio'}
        >
            {/* Reel-spin keyframes: only used while audio is buffering. Defining the
                animation in a <style> tag keeps everything self-contained — no global
                CSS file to keep in sync, and duplicate definitions across instances
                are inert. */}
            <style>{`
                @keyframes jp-reel-spin-${svgId} {
                    0%   { transform: translateY(0); filter: blur(0); }
                    50%  { transform: translateY(-3px); filter: blur(0.4px); }
                    100% { transform: translateY(0); filter: blur(0); }
                }
                .jp-reel-spin-${svgId} {
                    transform-origin: 50% 50%;
                    animation: jp-reel-spin-${svgId} 0.85s cubic-bezier(0.4, 0.0, 0.6, 1) infinite;
                }
            `}</style>

            <svg
                viewBox={`0 0 ${VIEW_W} ${VIEW_H}`}
                preserveAspectRatio="none"
                className="absolute inset-x-3 inset-y-0 h-full"
                // Scale the entire waveform to 80% of the card area, centered.
                // Leaves a generous margin around the visual so the audio tile
                // reads as embedded artwork instead of full-bleed chart fill —
                // matches the breathing room in the agency reference designs.
                // Play button + duration overlay sit OUTSIDE the SVG so they
                // are unaffected and stay anchored in the corners.
                style={{
                    width: 'calc(100% - 1.5rem)',
                    transform: 'scale(0.8)',
                    transformOrigin: 'center',
                }}
                aria-hidden="true"
            >
                {/* Bars + pay-line. No defs / mask / separators by design —
                    every previous chrome layer ended up reading as a frame.
                    The `jp-reel-spin` class only applies while audio is
                    buffering (slot-reel mid-spin cue). */}
                <g className={isLoading ? `jp-reel-spin-${svgId}` : undefined}>
                    {baseBars.map((bar, i) => {
                        const liveAmp = levels?.[i]
                        const topAmp = liveAmp != null ? Math.max(bar.top * 0.35, liveAmp) : bar.top
                        const botAmp = liveAmp != null ? Math.max(bar.bot * 0.35, liveAmp * 0.85) : bar.bot
                        const halfTop = (topAmp * VIEW_H) / 2
                        const halfBot = (botAmp * VIEW_H) / 2
                        // Soft-cornered (not full-pill) bar tips: rx = ry =
                        // bar.w * 0.28 gives a clearly rounded corner without
                        // the dome of a capsule. Full pill (bar.w / 2) read as
                        // "lozenges" in agency review; this is the middle
                        // ground — visibly soft, but the bar still reads as a
                        // bar. The min() guard against half-height stops very
                        // short amplitudes from rendering as squashed ellipses.
                        const r = Math.min(bar.w * 0.28, (halfTop + halfBot) / 2)
                        const cx = bar.x + bar.w / 2
                        const passed = progress > 0 && cx / VIEW_W <= progress
                        // Opacity rules (informed by agency review):
                        //   - Idle: every bar full opacity. NO per-bar
                        //     variation — random `(i % 4) * 0.07` reads as
                        //     "some bars are randomly darker for no reason".
                        //   - Playback active (playing OR buffering OR a
                        //     paused track with mid-progress): bars NOT yet
                        //     reached drop hard to 0.28 so the play-head
                        //     position is unmistakable as bars fill in
                        //     left-to-right. Bars already reached pop back
                        //     to full 1.0 — the contrast is the point.
                        // Always animate opacity (even when live amplitudes
                        // are driving height each frame) so the fill swelling
                        // past the play-head reads as a smooth wipe.
                        const playbackActive = isPlaying || isLoading || progress > 0
                        const barOpacity = passed ? 1 : playbackActive ? 0.28 : 1
                        return (
                            <rect
                                key={i}
                                x={bar.x}
                                y={CENTER_Y - halfTop}
                                width={bar.w}
                                height={halfTop + halfBot}
                                rx={r}
                                ry={r}
                                fill={primaryColor}
                                opacity={barOpacity}
                                style={{ transition: 'opacity 220ms ease' }}
                            />
                        )
                    })}

                    {/* Pay-line: 1px hairline across the exact center. Hidden
                        when idle, brightens on play, flashes on loud peaks via
                        payLinePulse. The only persistent slot-machine cue. */}
                    <line
                        x1={0}
                        y1={CENTER_Y}
                        x2={VIEW_W}
                        y2={CENTER_Y}
                        stroke={primaryColor}
                        strokeWidth={0.22}
                        opacity={payLineOpacity}
                        style={{ transition: 'opacity 140ms ease' }}
                    />
                </g>
            </svg>

            <button
                type="button"
                onClick={handlePlayClick}
                className={`absolute bottom-2 left-2 z-10 flex ${playButtonScale} items-center justify-center rounded-full bg-black/45 text-white shadow-lg ring-1 ring-white/25 backdrop-blur-md transition-transform duration-150 hover:scale-105 hover:bg-black/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-white`}
                aria-label={isPlaying ? 'Pause audio' : 'Play audio'}
                aria-pressed={isPlaying}
            >
                {isLoading ? (
                    <LoadingSpinner className={playIconScale} />
                ) : isPlaying ? (
                    <PauseIcon className={playIconScale} />
                ) : (
                    <PlayIcon className={`${playIconScale} translate-x-[1px]`} />
                )}
            </button>

            {(aiStatus === 'queued' || aiStatus === 'processing') && (
                <div className="pointer-events-none absolute right-2 top-2 z-10 flex items-center gap-1 rounded-full bg-white/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white/95 shadow-sm backdrop-blur-md">
                    <span className="relative flex h-1.5 w-1.5">
                        <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-white opacity-75" />
                        <span className="relative inline-flex h-1.5 w-1.5 rounded-full bg-white" />
                    </span>
                    AI
                </div>
            )}

            {duration && (
                <span className="pointer-events-none absolute bottom-2 right-2 z-10 rounded-md bg-black/55 px-2 py-0.5 font-mono text-[11px] font-medium text-white/95 shadow-sm backdrop-blur-md">
                    {duration}
                </span>
            )}

            {src ? (
                <audio
                    ref={audioRef}
                    src={src}
                    preload="metadata"
                    /*
                     * Only set `crossOrigin` when the live analyser is opted-in
                     * via config/assets.php audio.live_analyser_enabled. With
                     * the attribute set, every byte of the audio response must
                     * carry CORS headers — without that the browser rejects
                     * the source with `NotSupportedError: the element has no
                     * supported sources` and `play()` fails. Default off is
                     * the reliable choice; flip on per-environment once CDN
                     * responses include Access-Control-Allow-Origin (403 vs CORS
                     * vs decode errors are logged via useAudioPlayer (console only).
                     */
                    {...(isLiveAudioAnalyserEnabled() ? { crossOrigin: 'anonymous' } : {})}
                    className="hidden"
                />
            ) : null}
        </div>
    )
}
