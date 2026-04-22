import { useCallback, useEffect, useState } from 'react'
import { PauseIcon, PlayIcon } from '@heroicons/react/24/solid'

/**
 * Timeline for the AI clip previewed full-frame on the canvas (still layer → job output).
 * The canvas uses a single {@link HTMLVideoElement} without built-in controls; this bar is the primary transport.
 */
export function EditorStudioAnimationCanvasPreviewBar(props: {
    videoRef: React.RefObject<HTMLVideoElement | null>
    /** Used as range max before `loadedmetadata` (e.g. from job or output metadata). */
    fallbackDurationMs: number
    /** Changes when a different job is selected — resets local transport state. */
    previewKey: string
    /** Increment when the preview should start playing immediately (e.g. render just finished). */
    autoplaySignal?: number
}) {
    const { videoRef, fallbackDurationMs, previewKey, autoplaySignal = 0 } = props
    const [playheadMs, setPlayheadMs] = useState(0)
    const [durationMs, setDurationMs] = useState(() => Math.max(1000, fallbackDurationMs))
    const [playing, setPlaying] = useState(false)

    useEffect(() => {
        setPlaying(false)
        setPlayheadMs(0)
        setDurationMs(Math.max(1000, fallbackDurationMs))
        const v = videoRef.current
        if (v) {
            try {
                v.pause()
                v.currentTime = 0
            } catch {
                /* ignore */
            }
        }
    }, [previewKey, fallbackDurationMs, videoRef])

    useEffect(() => {
        if (!autoplaySignal) {
            return undefined
        }
        const v = videoRef.current
        if (!v) {
            return undefined
        }
        const tryPlay = () => {
            void (async () => {
                try {
                    v.currentTime = 0
                    await v.play()
                    setPlaying(true)
                } catch {
                    /* autoplay policy / decode */
                    setPlaying(false)
                }
            })()
        }
        if (v.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
            tryPlay()
        } else {
            v.addEventListener('canplay', tryPlay, { once: true })
        }
        return undefined
    }, [autoplaySignal, previewKey, videoRef])

    useEffect(() => {
        const v = videoRef.current
        if (!v) {
            return undefined
        }
        const onMeta = () => {
            if (v.duration && Number.isFinite(v.duration) && v.duration > 0) {
                setDurationMs(Math.round(v.duration * 1000))
            }
        }
        v.addEventListener('loadedmetadata', onMeta)
        onMeta()
        return () => v.removeEventListener('loadedmetadata', onMeta)
    }, [videoRef, previewKey])

    useEffect(() => {
        if (!playing) {
            return undefined
        }
        let raf = 0
        const tick = () => {
            const v = videoRef.current
            if (v) {
                setPlayheadMs(Math.min(Math.round(v.currentTime * 1000), durationMs))
            }
            raf = requestAnimationFrame(tick)
        }
        raf = requestAnimationFrame(tick)
        return () => cancelAnimationFrame(raf)
    }, [playing, videoRef, durationMs])

    const syncToMs = useCallback(
        (ms: number) => {
            const v = videoRef.current
            if (!v) {
                return
            }
            const cap = Math.max(0.1, durationMs / 1000)
            const t = Math.max(0, Math.min(ms / 1000, cap - 0.01))
            try {
                v.currentTime = t
            } catch {
                /* ignore */
            }
            setPlayheadMs(Math.round(t * 1000))
        },
        [videoRef, durationMs]
    )

    const togglePlay = useCallback(() => {
        const v = videoRef.current
        if (!v) {
            return
        }
        if (playing) {
            v.pause()
            setPlaying(false)
            return
        }
        void (async () => {
            try {
                v.currentTime = playheadMs / 1000
                await v.play()
            } catch {
                /* autoplay / decode */
            }
            setPlaying(true)
        })()
    }, [videoRef, playing, playheadMs])

    const fmt = (ms: number) => {
        const s = Math.floor(ms / 1000)
        const m = Math.floor(s / 60)
        const rs = s % 60
        return `${m}:${rs.toString().padStart(2, '0')}`
    }

    return (
        <div
            className="pointer-events-auto z-40 flex shrink-0 items-center gap-2 border-t border-indigo-900/50 bg-gray-900/95 px-3 py-1.5 shadow-[0_-4px_16px_rgba(0,0,0,0.12)]"
            data-testid="editor-studio-animation-canvas-preview-bar"
        >
            <span
                className="max-w-[12rem] text-[9px] font-semibold uppercase tracking-wider text-indigo-300/90"
                title="Previewing an AI video clip on the canvas. Use the list on the right to switch jobs."
            >
                AI clip preview
            </span>
            <button
                type="button"
                onClick={togglePlay}
                className="inline-flex h-7 w-7 items-center justify-center rounded-md border border-indigo-500/50 bg-gray-800 text-indigo-100 hover:border-indigo-400/60 hover:bg-gray-700"
                title={playing ? 'Pause' : 'Play'}
                aria-label={playing ? 'Pause preview' : 'Play preview'}
            >
                {playing ? <PauseIcon className="h-4 w-4" /> : <PlayIcon className="h-4 w-4" />}
            </button>
            <span className="tabular-nums text-[10px] text-gray-400">
                {fmt(playheadMs)} / {fmt(durationMs)}
            </span>
            <input
                type="range"
                min={0}
                max={Math.max(100, durationMs)}
                step={50}
                value={Math.min(playheadMs, durationMs)}
                onChange={(e) => {
                    const n = Number(e.target.value)
                    syncToMs(n)
                    if (playing && videoRef.current) {
                        void videoRef.current.play().catch(() => {})
                    }
                }}
                className="h-1.5 min-w-[8rem] flex-1 cursor-pointer accent-indigo-500"
                aria-label="AI clip playhead"
            />
        </div>
    )
}
