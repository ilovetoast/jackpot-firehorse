import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { PauseIcon, PlayIcon } from '@heroicons/react/24/solid'
import type { DocumentModel, Layer } from '../documentModel'
import { isVideoLayer } from '../documentModel'

function hasVisibleVideoLayer(layers: Layer[]): boolean {
    return layers.some((l) => l.visible && isVideoLayer(l))
}

/**
 * Composition playback: sync visible stage videos to a shared playhead (strip sits under the canvas).
 * Playhead is controlled by the parent so the shared {@link CompositionScene} can mirror `currentTimeMs`.
 */
export function EditorCompositionVideoPlaybackBar(props: {
    document: DocumentModel
    getStageEl: () => HTMLElement | null
    /** Increment to start synced playback (e.g. user selected a video layer). */
    autoplayNonce?: number
    playheadMs: number
    onPlayheadMsChange: (ms: number) => void
}) {
    const { document: doc, getStageEl, autoplayNonce = 0, playheadMs, onPlayheadMsChange } = props
    const durationMs = Math.max(1000, doc.studio_timeline?.duration_ms ?? 30_000)
    const videoLayerCount = useMemo(
        () => doc.layers.filter((l) => l.visible && isVideoLayer(l)).length,
        [doc.layers],
    )
    const anyVideo = useMemo(() => hasVisibleVideoLayer(doc.layers), [doc.layers])

    const [playing, setPlaying] = useState(false)

    const queryVideos = useCallback(() => {
        const stage = getStageEl()
        if (!stage) {
            return [] as HTMLVideoElement[]
        }
        return [...stage.querySelectorAll<HTMLVideoElement>('video[data-jp-editor-layer]')]
    }, [getStageEl])

    const queryVideosRef = useRef(queryVideos)
    queryVideosRef.current = queryVideos
    /** Only react to *new* autoplay requests — `getStageEl` is often an inline () => ref.current from the parent, so its identity changes every render and would otherwise re-fire this effect and undo pause. */
    const lastAutoplayNonceAppliedRef = useRef(0)

    const syncVideosToMs = useCallback(
        (ms: number) => {
            const t = Math.max(0, Math.min(ms / 1000, durationMs / 1000))
            for (const v of queryVideos()) {
                try {
                    v.currentTime = t
                } catch {
                    /* ignore */
                }
            }
            onPlayheadMsChange(Math.round(t * 1000))
        },
        [durationMs, queryVideos, onPlayheadMsChange],
    )

    useEffect(() => {
        if (!playing) {
            return undefined
        }
        let raf = 0
        const tick = () => {
            const vids = queryVideos()
            const v0 = vids[0]
            if (v0) {
                onPlayheadMsChange(Math.min(Math.round(v0.currentTime * 1000), durationMs))
            }
            raf = requestAnimationFrame(tick)
        }
        raf = requestAnimationFrame(tick)
        return () => cancelAnimationFrame(raf)
    }, [playing, durationMs, queryVideos, onPlayheadMsChange])

    useEffect(() => {
        if (!autoplayNonce) {
            lastAutoplayNonceAppliedRef.current = 0
            return undefined
        }
        if (autoplayNonce === lastAutoplayNonceAppliedRef.current) {
            return undefined
        }
        lastAutoplayNonceAppliedRef.current = autoplayNonce
        const vids = queryVideosRef.current()
        if (vids.length === 0) {
            return undefined
        }
        void (async () => {
            for (const v of vids) {
                try {
                    v.muted = true
                    await v.play()
                } catch {
                    /* autoplay / decode */
                }
            }
            setPlaying(true)
        })()
        return undefined
    }, [autoplayNonce])

    const togglePlay = useCallback(() => {
        const vids = queryVideos()
        if (vids.length === 0) {
            return
        }
        if (playing) {
            for (const v of vids) {
                void v.pause()
            }
            setPlaying(false)
            return
        }
        void (async () => {
            for (const v of vids) {
                try {
                    v.currentTime = playheadMs / 1000
                    await v.play()
                } catch {
                    /* autoplay / decode */
                }
            }
            setPlaying(true)
        })()
    }, [queryVideos, playing, playheadMs])

    if (videoLayerCount === 0 || !anyVideo) {
        return null
    }

    const fmt = (ms: number) => {
        const s = Math.floor(ms / 1000)
        const m = Math.floor(s / 60)
        const rs = s % 60
        return `${m}:${rs.toString().padStart(2, '0')}`
    }

    return (
        <div
            className="pointer-events-auto z-40 flex shrink-0 items-center gap-2 border-t border-indigo-900/50 bg-gray-900/95 px-3 py-1.5 shadow-[0_-4px_16px_rgba(0,0,0,0.12)]"
            data-testid="editor-composition-video-playback-bar"
        >
            <span
                className="max-w-[10rem] text-[9px] font-semibold uppercase tracking-wider text-indigo-300/90"
                title="Scrub the clip, or use the on-canvas player when a video layer is selected."
            >
                Video playback
            </span>
            <button
                type="button"
                onClick={togglePlay}
                className="inline-flex h-7 w-7 items-center justify-center rounded-md border border-indigo-500/50 bg-gray-800 text-indigo-100 hover:border-indigo-400/60 hover:bg-gray-700"
                title={playing ? 'Pause' : 'Play'}
                aria-label={playing ? 'Pause videos' : 'Play videos'}
            >
                {playing ? <PauseIcon className="h-4 w-4" /> : <PlayIcon className="h-4 w-4" />}
            </button>
            <span className="tabular-nums text-[10px] text-gray-400">
                {fmt(playheadMs)} / {fmt(durationMs)}
            </span>
            <input
                type="range"
                min={0}
                max={durationMs}
                step={50}
                value={playheadMs}
                onChange={(e) => {
                    const v = Number(e.target.value)
                    syncVideosToMs(v)
                    if (playing) {
                        for (const vid of queryVideos()) {
                            void vid.play().catch(() => {})
                        }
                    }
                }}
                className="h-1.5 min-w-[8rem] flex-1 cursor-pointer accent-indigo-500"
                aria-label="Composition playhead"
            />
            {videoLayerCount > 1 ? (
                <span className="hidden text-[9px] text-gray-500 sm:inline" title="Primary = export checkbox, else back-most">
                    {videoLayerCount} video layers — scrub syncs all
                </span>
            ) : null}
        </div>
    )
}
