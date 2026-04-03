import { useState, useEffect, useCallback, useRef } from 'react'
import { motion } from 'framer-motion'

const NUM_COLUMNS = 3
/** Cap thumbnails passed from overview (backend may send more). */
const MAX_ASSETS = 24
/** Film-strip scroll only when there is enough to fill columns (~5 per col); below this use classic bottom-aligned masonry. */
const MIN_ASSETS_FOR_COLUMN_DRIFT = 15

/** Vertical stagger between columns (slot-machine reel offset) */
const COLUMN_BOTTOM_OFFSETS = ['-42%', '0%', '48%']

/** Per-card horizontal nudge (px) for subtle reel wobble within each column */
const CARD_HORIZONTAL_NUDGE = [
    [0, 4, -3, 5, -2, 4],
    [0, -3, 4, -4, 3, -2],
    [0, 3, -4, 2, -5, 3],
]

function getCardNudge(colIndex, cardIndex) {
    const arr = CARD_HORIZONTAL_NUDGE[colIndex % NUM_COLUMNS]
    return arr[cardIndex % arr.length] ?? 0
}

export default function AssetCollage({
    assets = [],
    fastEntrance = false,
    /** First N primary (non-loop) images use eager + fetchPriority high (hero tier). */
    eagerImageCount = 4,
}) {
    const [visible, setVisible] = useState(false)
    const [mouseOffset, setMouseOffset] = useState({ x: 0, y: 0 })
    const rafRef = useRef(null)
    const lastPosRef = useRef({ x: 0, y: 0 })

    useEffect(() => {
        if (fastEntrance) {
            setVisible(true)
            return undefined
        }
        const t = setTimeout(() => setVisible(true), 300)
        return () => clearTimeout(t)
    }, [fastEntrance])

    const handleMouseMove = useCallback((e) => {
        const cx = window.innerWidth / 2
        const cy = window.innerHeight / 2
        lastPosRef.current = {
            x: (e.clientX - cx) * 0.004,
            y: (e.clientY - cy) * 0.004,
        }
        if (rafRef.current) return
        rafRef.current = requestAnimationFrame(() => {
            rafRef.current = null
            setMouseOffset(lastPosRef.current)
        })
    }, [])

    useEffect(() => {
        window.addEventListener('mousemove', handleMouseMove, { passive: true })
        return () => {
            window.removeEventListener('mousemove', handleMouseMove)
            if (rafRef.current) cancelAnimationFrame(rafRef.current)
        }
    }, [handleMouseMove])

    // Hero tier passes small thumbs; full assets payload uses medium+ for quality.
    const thumbs = assets
        .slice(0, MAX_ASSETS)
        .map((a) => a.final_thumbnail_url || a.thumbnail_url)
        .filter(Boolean)

    const hasAssets = thumbs.length > 0
    const isFew = thumbs.length <= 2
    const enableColumnDrift = thumbs.length >= MIN_ASSETS_FOR_COLUMN_DRIFT && !isFew

    if (!hasAssets) return null

    // Distribute assets round-robin across 3 columns
    const columns = Array.from({ length: NUM_COLUMNS }, () => [])
    thumbs.forEach((src, i) => {
        columns[i % NUM_COLUMNS].push(src)
    })

    // For 1 asset: center in middle column; for 2: col 0 and col 2
    const displayColumns = isFew && hasAssets
        ? thumbs.length === 1
            ? [[], thumbs, []]
            : [[thumbs[0]], [], [thumbs[1]]]
        : columns

    const parallaxStyle = {
        transform: `translate3d(${mouseOffset.x}px, ${mouseOffset.y}px, 0)`,
        transition: 'transform 0.8s cubic-bezier(0.25, 0.1, 0.25, 1)',
        willChange: 'transform',
        backfaceVisibility: 'hidden',
    }

    // Pairs with Overview/Index.jsx left column (lg:max-w-[50%], lg:mx-0). Do not widen to full-bleed or move without updating that layout contract.
    return (
        <div
            className="absolute right-0 bottom-0 h-full w-[38%] pointer-events-none hidden lg:block overflow-hidden"
            style={{
                contain: 'layout paint',
                perspective: '1200px',
                maskImage: 'linear-gradient(to bottom, transparent 0%, black 22%, black 85%, transparent 100%)',
                WebkitMaskImage: 'linear-gradient(to bottom, transparent 0%, black 22%, black 85%, transparent 100%)',
                maskSize: '100% 100%',
                maskRepeat: 'no-repeat',
                maskPosition: 'center',
            }}
        >
            <div
                className={`absolute inset-0 grid grid-cols-3 gap-8 justify-items-center px-6 pb-4 ${
                    enableColumnDrift ? 'items-stretch' : 'items-end'
                }`}
                style={{
                    ...parallaxStyle,
                    transformStyle: 'preserve-3d',
                }}
            >
                {(() => {
                    let photoStagger = 0
                    let primaryImageIndex = 0
                    return displayColumns.map((imgs, ci) => {
                    const isEmpty = !imgs || imgs.length === 0
                    const useFilmDrift = enableColumnDrift && !isEmpty
                    const driftClass = useFilmDrift ? `animate-collage-scroll-${(ci % 3) + 1}` : ''

                    const renderCards = (list, keySuffix = '') =>
                        list.map((src, ii) => {
                            const nudge = getCardNudge(ci, ii)
                            const staggerIndex = keySuffix === '' ? photoStagger++ : 0
                            const priorityIndex = keySuffix === '' ? primaryImageIndex++ : 9999
                            const highPriority = priorityIndex < eagerImageCount
                            const cardClass =
                                'w-full rounded-2xl overflow-hidden ring-1 ring-white/[0.06] shadow-[0_8px_30px_rgba(0,0,0,0.5)] shrink-0'
                            const cardStyle = {
                                aspectRatio: isFew ? '3/4' : '4/5',
                                marginLeft: nudge !== 0 ? `${nudge}px` : undefined,
                                contain: 'layout paint',
                                transform: 'translateZ(0)',
                            }
                            const img = (
                                <img
                                    src={src}
                                    alt=""
                                    className="h-full w-full object-cover"
                                    loading={highPriority ? 'eager' : 'lazy'}
                                    decoding="async"
                                    fetchPriority={highPriority ? 'high' : 'low'}
                                />
                            )
                            if (keySuffix !== '') {
                                return (
                                    <div key={`${ci}-${ii}-${keySuffix}`} className={cardClass} style={cardStyle}>
                                        {img}
                                    </div>
                                )
                            }
                            return (
                                <motion.div
                                    key={`${ci}-${ii}-${keySuffix}`}
                                    className={cardClass}
                                    style={cardStyle}
                                    initial={{ opacity: 0, y: 18, scale: 0.96, filter: 'blur(8px)' }}
                                    animate={{ opacity: 1, y: 0, scale: 1, filter: 'blur(0px)' }}
                                    transition={{
                                        duration: 0.52,
                                        delay: staggerIndex * 0.06,
                                        ease: [0.22, 1, 0.36, 1],
                                    }}
                                >
                                    {img}
                                </motion.div>
                            )
                        })

                    return (
                        <div
                            key={ci}
                            className={
                                useFilmDrift
                                    ? 'relative flex min-h-0 h-full w-full max-w-full flex-col justify-end overflow-hidden'
                                    : 'flex min-h-0 w-full max-w-full flex-col-reverse justify-end'
                            }
                            style={{
                                marginBottom: COLUMN_BOTTOM_OFFSETS[ci] ?? '0%',
                                transition: `opacity 0.8s ease ${200 + ci * 120}ms`,
                                opacity: visible ? (ci === 1 && isFew ? 1 : 0.92) : 0,
                                willChange: visible ? 'auto' : 'opacity',
                                backfaceVisibility: 'hidden',
                            }}
                        >
                            {isEmpty ? (
                                <div
                                    className="w-full rounded-2xl border border-dashed border-white/[0.06] flex-1 min-h-[80px]"
                                    aria-hidden
                                />
                            ) : useFilmDrift ? (
                                <div
                                    className={`flex w-full shrink-0 flex-col gap-3 ${driftClass}`}
                                    style={{
                                        willChange: 'transform',
                                        backfaceVisibility: 'hidden',
                                    }}
                                >
                                    {renderCards(imgs, '')}
                                    {renderCards(imgs, '-loop')}
                                </div>
                            ) : (
                                <div className="flex min-h-0 w-full flex-col-reverse gap-3 justify-end">
                                    {renderCards(imgs, '')}
                                </div>
                            )}
                        </div>
                    )
                })
                })()}
            </div>
        </div>
    )
}
