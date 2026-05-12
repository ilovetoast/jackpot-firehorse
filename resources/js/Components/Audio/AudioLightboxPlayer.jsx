import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Pause, Play, Rewind, FastForward, Volume2, VolumeX } from 'lucide-react'
import AudioCardVisual from './AudioCardVisual'
import useAudioPlayer from '../../hooks/useAudioPlayer'

/**
 * Lightbox-grade audio player.
 *
 * Reuses the same AudioCardVisual at `size="lightbox"` for the waveform,
 * with a dedicated transport panel underneath: skip ±10s, big play/pause,
 * draggable scrubber, time readout, speed control, mute / volume slider,
 * and an optional transcript / mood drawer when AI analysis is present.
 *
 * Architecture note — there is exactly ONE `<audio>` element on the page
 * per audio asset (rendered inside AudioCardVisual). The lightbox's
 * `useAudioPlayer` resolves that element through the shared registry's
 * per-token map, so the lightbox transport drives the same audio element
 * the visualization is reading from. No duplicate downloads, no desync.
 */
const SKIP_SECONDS = 10
const SPEED_STEPS = [1, 1.25, 1.5, 1.75, 2, 0.75]

function formatTime(seconds) {
    if (!Number.isFinite(seconds) || seconds < 0) return '0:00'
    const total = Math.floor(seconds)
    const m = Math.floor(total / 60)
    const s = total % 60
    if (m >= 60) {
        const h = Math.floor(m / 60)
        const mm = m % 60
        return `${h}:${String(mm).padStart(2, '0')}:${String(s).padStart(2, '0')}`
    }
    return `${m}:${String(s).padStart(2, '0')}`
}

function formatRate(rate) {
    if (!Number.isFinite(rate)) return '1x'
    if (Math.abs(rate - Math.round(rate)) < 0.01) return `${Math.round(rate)}x`
    return `${rate.toFixed(2).replace(/0$/, '')}x`
}

export default function AudioLightboxPlayer({ asset, primaryColor = '#f97316' }) {
    const audioMeta = asset?.metadata?.audio || {}
    const transcript = audioMeta?.transcript
    const summary = audioMeta?.summary
    const mood = audioMeta?.mood
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
    const {
        isPlaying,
        isLoading,
        currentTime,
        duration,
        buffered,
        playbackRate,
        seekTo,
        skip,
        toggle,
        setPlaybackRate,
    } = useAudioPlayer({ token, src })

    // Drag-aware scrubber: while the user is dragging, the visual
    // position is driven by the drag itself (currentTime would lag the
    // pointer), and the seek only commits on release. This makes the
    // bar feel rock-solid instead of "jumping back" mid-drag.
    const seekBarRef = useRef(null)
    const [scrubRatio, setScrubRatio] = useState(null)
    const [isDragging, setIsDragging] = useState(false)

    const ratioForClient = useCallback((clientX) => {
        const bar = seekBarRef.current
        if (!bar) return 0
        const rect = bar.getBoundingClientRect()
        return Math.min(1, Math.max(0, (clientX - rect.left) / rect.width))
    }, [])

    const onPointerDown = (e) => {
        if (!duration) return
        e.preventDefault()
        const ratio = ratioForClient(e.clientX)
        setScrubRatio(ratio)
        setIsDragging(true)
        try {
            e.currentTarget.setPointerCapture?.(e.pointerId)
        } catch (_) {}
    }
    const onPointerMove = (e) => {
        if (!isDragging) return
        setScrubRatio(ratioForClient(e.clientX))
    }
    const onPointerUp = (e) => {
        if (!isDragging || !duration) return
        const ratio = ratioForClient(e.clientX)
        seekTo(ratio * duration)
        setIsDragging(false)
        setScrubRatio(null)
        try {
            e.currentTarget.releasePointerCapture?.(e.pointerId)
        } catch (_) {}
    }
    const onPointerCancel = () => {
        setIsDragging(false)
        setScrubRatio(null)
    }

    const liveProgress = duration > 0 ? currentTime / duration : 0
    const displayProgress = isDragging && scrubRatio != null ? scrubRatio : liveProgress
    const displayTime = isDragging && scrubRatio != null ? scrubRatio * duration : currentTime

    const bufferedPct =
        buffered && duration > 0 ? Math.min(100, (buffered.end / duration) * 100) : 0

    const cycleSpeed = () => {
        const idx = SPEED_STEPS.findIndex((r) => Math.abs(r - playbackRate) < 0.01)
        const next = SPEED_STEPS[(idx + 1) % SPEED_STEPS.length] ?? 1
        setPlaybackRate(next)
    }

    // Keyboard transport: space toggles play, ←/→ skip, J/K/L mimic
    // youtube-style controls. Scoped to the lightbox via the rootRef
    // (we listen on document so the user doesn't need to click the
    // panel to focus it, but ignore events while typing in inputs).
    const rootRef = useRef(null)
    useEffect(() => {
        const handler = (e) => {
            const tag = (e.target?.tagName || '').toLowerCase()
            if (tag === 'input' || tag === 'textarea' || e.target?.isContentEditable) return
            if (!rootRef.current || !document.body.contains(rootRef.current)) return
            switch (e.key) {
                case ' ':
                case 'k':
                case 'K':
                    e.preventDefault()
                    toggle()
                    break
                case 'ArrowLeft':
                case 'j':
                case 'J':
                    e.preventDefault()
                    skip(-SKIP_SECONDS)
                    break
                case 'ArrowRight':
                case 'l':
                case 'L':
                    e.preventDefault()
                    skip(SKIP_SECONDS)
                    break
                default:
                    break
            }
        }
        document.addEventListener('keydown', handler)
        return () => document.removeEventListener('keydown', handler)
    }, [toggle, skip])

    return (
        <div
            ref={rootRef}
            className="flex w-full max-w-3xl flex-col gap-4 rounded-2xl bg-slate-950 p-6 text-white shadow-2xl"
        >
            <div className="flex items-baseline justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="truncate text-lg font-semibold">
                        {asset?.title || asset?.original_filename || 'Audio'}
                    </h2>
                    {(audioMeta?.bitrate || audioMeta?.codec || audioMeta?.sample_rate) && (
                        <p className="mt-0.5 text-xs text-slate-400">
                            {audioMeta?.codec ? audioMeta.codec.toUpperCase() : ''}
                            {audioMeta?.bitrate ? ` · ${Math.round(audioMeta.bitrate / 1000)} kbps` : ''}
                            {audioMeta?.sample_rate ? ` · ${(audioMeta.sample_rate / 1000).toFixed(1)} kHz` : ''}
                            {audioMeta?.channels ? ` · ${audioMeta.channels === 1 ? 'mono' : `${audioMeta.channels}ch`}` : ''}
                        </p>
                    )}
                </div>
                <div className="font-mono text-sm tabular-nums text-slate-200">
                    {formatTime(displayTime)} / {formatTime(duration)}
                </div>
            </div>

            <div className="relative h-44 w-full overflow-hidden rounded-xl">
                <AudioCardVisual asset={asset} primaryColor={primaryColor} size="lightbox" className="rounded-xl" />
            </div>

            {/* Scrubber. The hit target is taller than the visible bar
                (py-2.5) so it's comfortable to grab on touch — the bar
                itself stays a hairline. */}
            <div
                ref={seekBarRef}
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={onPointerUp}
                onPointerCancel={onPointerCancel}
                className="group relative cursor-pointer touch-none select-none py-2.5"
                role="slider"
                aria-label="Seek"
                aria-valuemin={0}
                aria-valuemax={duration || 0}
                aria-valuenow={Math.round(displayTime)}
                aria-valuetext={`${formatTime(displayTime)} of ${formatTime(duration)}`}
                tabIndex={0}
            >
                <div className="relative h-1.5 w-full overflow-hidden rounded-full bg-white/10">
                    <div
                        className="absolute inset-y-0 left-0 rounded-full bg-white/15"
                        style={{ width: `${bufferedPct}%` }}
                        aria-hidden="true"
                    />
                    <div
                        className="absolute inset-y-0 left-0 rounded-full transition-[width] duration-75"
                        style={{ width: `${displayProgress * 100}%`, backgroundColor: primaryColor }}
                        aria-hidden="true"
                    />
                </div>
                <div
                    className="pointer-events-none absolute top-1/2 h-3.5 w-3.5 -translate-y-1/2 -translate-x-1/2 rounded-full bg-white opacity-0 shadow ring-2 transition-opacity duration-150 group-hover:opacity-100"
                    style={{
                        left: `${displayProgress * 100}%`,
                        ringColor: primaryColor,
                        opacity: isDragging ? 1 : undefined,
                    }}
                    aria-hidden="true"
                />
            </div>

            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => skip(-SKIP_SECONDS)}
                        disabled={!duration}
                        className="flex h-9 w-9 items-center justify-center rounded-full bg-white/5 text-slate-200 transition hover:bg-white/15 hover:text-white disabled:cursor-not-allowed disabled:opacity-40"
                        aria-label={`Rewind ${SKIP_SECONDS} seconds`}
                        title={`Rewind ${SKIP_SECONDS}s (←)`}
                    >
                        <Rewind className="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        onClick={toggle}
                        disabled={!src}
                        className="flex h-11 w-11 items-center justify-center rounded-full bg-white text-slate-900 shadow-lg transition hover:scale-105 disabled:cursor-not-allowed disabled:opacity-40"
                        aria-label={isPlaying ? 'Pause' : 'Play'}
                        aria-pressed={isPlaying}
                        title={isPlaying ? 'Pause (space)' : 'Play (space)'}
                    >
                        {isLoading ? (
                            <span className="h-4 w-4 animate-spin rounded-full border-2 border-slate-300 border-t-slate-900" />
                        ) : isPlaying ? (
                            <Pause className="h-5 w-5" />
                        ) : (
                            <Play className="ml-0.5 h-5 w-5" />
                        )}
                    </button>
                    <button
                        type="button"
                        onClick={() => skip(SKIP_SECONDS)}
                        disabled={!duration}
                        className="flex h-9 w-9 items-center justify-center rounded-full bg-white/5 text-slate-200 transition hover:bg-white/15 hover:text-white disabled:cursor-not-allowed disabled:opacity-40"
                        aria-label={`Fast forward ${SKIP_SECONDS} seconds`}
                        title={`Forward ${SKIP_SECONDS}s (→)`}
                    >
                        <FastForward className="h-4 w-4" />
                    </button>
                </div>

                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={cycleSpeed}
                        className="rounded-md bg-white/5 px-2 py-1 font-mono text-xs font-medium text-slate-200 transition hover:bg-white/15 hover:text-white"
                        aria-label="Playback speed"
                        title="Cycle playback speed"
                    >
                        {formatRate(playbackRate)}
                    </button>
                    <VolumeRow primaryColor={primaryColor} />
                </div>
            </div>

            {(transcript || summary || mood) && (
                <div className="mt-1 grid gap-3 rounded-xl bg-white/5 p-4 text-sm ring-1 ring-white/10">
                    {summary && (
                        <div>
                            <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Summary</p>
                            <p className="mt-1 text-slate-200">{summary}</p>
                        </div>
                    )}
                    {mood && (
                        <div>
                            <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Mood</p>
                            <p className="mt-1 text-slate-200">{Array.isArray(mood) ? mood.join(', ') : mood}</p>
                        </div>
                    )}
                    {transcript && (
                        <details className="text-slate-200" open={!summary}>
                            <summary className="cursor-pointer text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                                Transcript
                            </summary>
                            <p className="mt-2 max-h-64 overflow-y-auto whitespace-pre-line text-sm leading-relaxed">
                                {transcript}
                            </p>
                        </details>
                    )}
                </div>
            )}

            {!transcript && !summary && !mood && aiStatus && aiStatus !== 'completed' && (
                <p className="text-xs italic text-slate-400">
                    {aiStatus === 'pending_provider'
                        ? 'AI analysis is not available for this file right now.'
                        : `AI analysis status: ${aiStatus}`}
                </p>
            )}

            {/* Keyboard shortcut legend — below the fold of every panel
                state so power users discover them on first hover-pause. */}
            <p className="text-[10px] uppercase tracking-wide text-slate-500">
                Space: play / pause &nbsp;·&nbsp; ← →: skip {SKIP_SECONDS}s
            </p>

            <span className="sr-only" aria-live="polite">
                {isPlaying ? 'Audio playing' : 'Audio paused'}
            </span>
        </div>
    )
}

function VolumeRow({ primaryColor }) {
    const [muted, setMuted] = React.useState(false)
    const [volume, setVolume] = React.useState(1)

    // Direct DOM manipulation of every audio element on the page — the
    // simplest way to honor "single shared audio context, single
    // currently-playing element" without prop-drilling refs.
    const apply = (nextVolume, nextMuted) => {
        try {
            const audios = typeof document !== 'undefined' ? document.querySelectorAll('audio') : []
            audios.forEach((a) => {
                a.volume = nextVolume
                a.muted = nextMuted
            })
        } catch (_) {}
    }

    return (
        <div className="flex items-center gap-2">
            <button
                type="button"
                onClick={() => {
                    const next = !muted
                    setMuted(next)
                    apply(volume, next)
                }}
                className="text-slate-400 hover:text-white"
                aria-label={muted ? 'Unmute' : 'Mute'}
            >
                {muted ? <VolumeX className="h-4 w-4" /> : <Volume2 className="h-4 w-4" />}
            </button>
            <input
                type="range"
                min={0}
                max={1}
                step={0.01}
                value={muted ? 0 : volume}
                onChange={(e) => {
                    const next = Number(e.target.value)
                    setVolume(next)
                    const nextMuted = next === 0
                    setMuted(nextMuted)
                    apply(next, nextMuted)
                }}
                className="h-1 w-24 cursor-pointer"
                style={{ accentColor: primaryColor }}
                aria-label="Volume"
            />
        </div>
    )
}
