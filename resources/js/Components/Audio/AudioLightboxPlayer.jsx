import React, { useEffect, useMemo, useRef, useState } from 'react'
import { Pause, Play, Volume2, VolumeX } from 'lucide-react'

/**
 * Lightbox-grade audio player for audio assets in the DAM.
 *
 * Shows three layers, top to bottom:
 *   1. The FFmpeg waveform PNG as a static "map of the song" — clickable
 *      for instant seek (each pixel maps proportionally to track time).
 *   2. A Web Audio `AnalyserNode` rendered onto a canvas as live
 *      frequency bars while the track plays. This is the "visual as it
 *      plays" the user requested.
 *   3. Standard transport controls (play / pause / seek / volume) and
 *      a transcript / mood drawer when AI analysis is available.
 *
 * Drop this into your existing lightbox container — it is a
 * self-contained block that takes width/height from its parent.
 */
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

export default function AudioLightboxPlayer({ asset, src, waveformUrl }) {
    const audioRef = useRef(null)
    const canvasRef = useRef(null)
    const audioCtxRef = useRef(null)
    const analyserRef = useRef(null)
    const animationRef = useRef(null)
    const seekBarRef = useRef(null)

    const [isPlaying, setIsPlaying] = useState(false)
    const [duration, setDuration] = useState(0)
    const [currentTime, setCurrentTime] = useState(0)
    const [volume, setVolume] = useState(1)
    const [isMuted, setIsMuted] = useState(false)
    const [audioError, setAudioError] = useState(null)

    const audioMeta = asset?.metadata?.audio || {}
    const transcript = audioMeta?.transcript
    const summary = audioMeta?.summary
    const mood = audioMeta?.mood
    const aiStatus = audioMeta?.ai_status

    const resolvedSrc = src || asset?.original_url || asset?.preview_url || ''
    const resolvedWaveform = waveformUrl || asset?.audio_waveform_url || asset?.thumbnail_url || null

    useEffect(() => {
        return () => {
            if (animationRef.current) {
                cancelAnimationFrame(animationRef.current)
            }
            if (audioCtxRef.current) {
                audioCtxRef.current.close().catch(() => {})
                audioCtxRef.current = null
                analyserRef.current = null
            }
        }
    }, [])

    const initAudioGraph = () => {
        if (audioCtxRef.current || !audioRef.current) return
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext
            if (!Ctx) return
            const ctx = new Ctx()
            const source = ctx.createMediaElementSource(audioRef.current)
            const analyser = ctx.createAnalyser()
            analyser.fftSize = 256
            source.connect(analyser)
            analyser.connect(ctx.destination)
            audioCtxRef.current = ctx
            analyserRef.current = analyser
        } catch (e) {
            console.warn('[AudioLightboxPlayer] AudioContext init failed', e)
        }
    }

    const drawFrame = () => {
        const canvas = canvasRef.current
        const analyser = analyserRef.current
        if (!canvas || !analyser) return

        const ctx2d = canvas.getContext('2d')
        const buffer = new Uint8Array(analyser.frequencyBinCount)
        analyser.getByteFrequencyData(buffer)

        const w = canvas.width
        const h = canvas.height
        ctx2d.clearRect(0, 0, w, h)

        const barCount = buffer.length
        const barWidth = Math.max(1, (w / barCount) * 1.5)
        let x = 0
        for (let i = 0; i < barCount; i++) {
            const value = buffer[i] / 255
            const barHeight = value * h
            const r = Math.round(99 + value * 60)
            const g = Math.round(102 + value * 80)
            const b = Math.round(241)
            ctx2d.fillStyle = `rgb(${r}, ${g}, ${b})`
            ctx2d.fillRect(x, h - barHeight, barWidth, barHeight)
            x += barWidth + 1
            if (x > w) break
        }

        animationRef.current = requestAnimationFrame(drawFrame)
    }

    const handlePlay = async () => {
        if (!audioRef.current) return
        initAudioGraph()
        try {
            if (audioCtxRef.current?.state === 'suspended') {
                await audioCtxRef.current.resume()
            }
            await audioRef.current.play()
            setIsPlaying(true)
            if (animationRef.current) cancelAnimationFrame(animationRef.current)
            animationRef.current = requestAnimationFrame(drawFrame)
        } catch (e) {
            console.warn('[AudioLightboxPlayer] play failed', e)
            setAudioError('Unable to play this audio.')
        }
    }

    const handlePause = () => {
        audioRef.current?.pause()
        setIsPlaying(false)
        if (animationRef.current) {
            cancelAnimationFrame(animationRef.current)
            animationRef.current = null
        }
    }

    const handleTimeUpdate = () => {
        if (!audioRef.current) return
        setCurrentTime(audioRef.current.currentTime)
    }

    const handleLoadedMetadata = () => {
        if (!audioRef.current) return
        setDuration(audioRef.current.duration)
    }

    const handleSeekClick = (e) => {
        const bar = seekBarRef.current
        const audio = audioRef.current
        if (!bar || !audio || !duration) return
        const rect = bar.getBoundingClientRect()
        const ratio = Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width))
        audio.currentTime = ratio * duration
        setCurrentTime(audio.currentTime)
    }

    const toggleMute = () => {
        if (!audioRef.current) return
        const next = !isMuted
        audioRef.current.muted = next
        setIsMuted(next)
    }

    const handleVolumeChange = (e) => {
        const next = Number(e.target.value)
        setVolume(next)
        if (audioRef.current) {
            audioRef.current.volume = next
            audioRef.current.muted = next === 0
            setIsMuted(next === 0)
        }
    }

    const progress = useMemo(() => (duration > 0 ? (currentTime / duration) * 100 : 0), [currentTime, duration])

    return (
        <div className="flex w-full max-w-3xl flex-col gap-4 rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900">
            <div className="flex items-baseline justify-between">
                <div>
                    <h2 className="text-lg font-semibold text-slate-900 dark:text-slate-100">
                        {asset?.title || asset?.original_filename || 'Audio'}
                    </h2>
                    {audioMeta?.bitrate && (
                        <p className="text-xs text-slate-500 dark:text-slate-400">
                            {Math.round((audioMeta.bitrate || 0) / 1000)} kbps · {audioMeta?.codec || ''} ·{' '}
                            {audioMeta?.sample_rate ? `${(audioMeta.sample_rate / 1000).toFixed(1)} kHz` : ''}
                        </p>
                    )}
                </div>
                <div className="font-mono text-sm tabular-nums text-slate-700 dark:text-slate-300">
                    {formatTime(currentTime)} / {formatTime(duration)}
                </div>
            </div>

            <div className="relative h-40 w-full overflow-hidden rounded-xl bg-slate-100 dark:bg-slate-800">
                {resolvedWaveform && (
                    <img
                        src={resolvedWaveform}
                        alt="Waveform"
                        className="absolute inset-0 h-full w-full select-none object-cover opacity-50"
                        draggable={false}
                    />
                )}
                <canvas
                    ref={canvasRef}
                    width={1024}
                    height={160}
                    className="relative h-full w-full"
                    aria-hidden="true"
                />
                <div
                    className="pointer-events-none absolute inset-y-0 left-0 bg-indigo-500/15"
                    style={{ width: `${progress}%` }}
                />
            </div>

            <div
                ref={seekBarRef}
                onClick={handleSeekClick}
                className="group relative h-2 w-full cursor-pointer overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"
                role="slider"
                aria-label="Seek"
                aria-valuemin={0}
                aria-valuemax={duration || 0}
                aria-valuenow={currentTime}
            >
                <div
                    className="h-full rounded-full bg-indigo-600 transition-[width] duration-75 group-hover:bg-indigo-500"
                    style={{ width: `${progress}%` }}
                />
            </div>

            <div className="flex items-center gap-4">
                <button
                    type="button"
                    onClick={isPlaying ? handlePause : handlePlay}
                    className="flex h-12 w-12 items-center justify-center rounded-full bg-indigo-600 text-white shadow-md transition hover:bg-indigo-500"
                    aria-label={isPlaying ? 'Pause' : 'Play'}
                >
                    {isPlaying ? <Pause className="h-5 w-5" /> : <Play className="h-5 w-5 translate-x-0.5" />}
                </button>

                <div className="flex flex-1 items-center gap-2">
                    <button
                        type="button"
                        onClick={toggleMute}
                        className="text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200"
                        aria-label={isMuted ? 'Unmute' : 'Mute'}
                    >
                        {isMuted ? <VolumeX className="h-4 w-4" /> : <Volume2 className="h-4 w-4" />}
                    </button>
                    <input
                        type="range"
                        min={0}
                        max={1}
                        step={0.01}
                        value={isMuted ? 0 : volume}
                        onChange={handleVolumeChange}
                        className="h-1 w-32 cursor-pointer accent-indigo-600"
                        aria-label="Volume"
                    />
                </div>
            </div>

            <audio
                ref={audioRef}
                src={resolvedSrc}
                preload="metadata"
                onTimeUpdate={handleTimeUpdate}
                onLoadedMetadata={handleLoadedMetadata}
                onEnded={handlePause}
                onError={() => setAudioError('Unable to load this audio file.')}
                crossOrigin="anonymous"
                className="hidden"
            />

            {audioError && (
                <p className="text-sm text-red-600 dark:text-red-400" role="alert">
                    {audioError}
                </p>
            )}

            {(transcript || summary || mood) && (
                <div className="mt-2 grid gap-3 rounded-xl bg-slate-50 p-4 text-sm dark:bg-slate-800">
                    {summary && (
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Summary</p>
                            <p className="text-slate-700 dark:text-slate-200">{summary}</p>
                        </div>
                    )}
                    {mood && (
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Mood</p>
                            <p className="text-slate-700 dark:text-slate-200">{Array.isArray(mood) ? mood.join(', ') : mood}</p>
                        </div>
                    )}
                    {transcript && (
                        <details className="text-slate-700 dark:text-slate-200">
                            <summary className="cursor-pointer text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Transcript
                            </summary>
                            <p className="mt-2 max-h-64 overflow-y-auto whitespace-pre-line text-sm">
                                {transcript}
                            </p>
                        </details>
                    )}
                </div>
            )}

            {!transcript && !summary && !mood && aiStatus && aiStatus !== 'completed' && (
                <p className="text-xs italic text-slate-500 dark:text-slate-400">
                    {aiStatus === 'pending_provider'
                        ? 'AI analysis is queued — configure ASSET_AUDIO_AI_PROVIDER to generate transcript and mood.'
                        : `AI analysis status: ${aiStatus}`}
                </p>
            )}
        </div>
    )
}
