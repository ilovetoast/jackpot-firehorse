/**
 * SlotReelLoader — Compact slot-reel processing animation.
 *
 * 4 columns that spin continuously (like the homepage SlotMachineLogo)
 * but sized and timed for an inline loading indicator. When `landed`
 * is true the reels snap to JACKPOT and burst particles.
 */
import { useState, useEffect, useRef, useCallback, useMemo } from 'react'

const SYMBOLS = {
    j: '/jp-parts/slot-j.svg',
    a: '/jp-parts/slot-a.svg',
    c: '/jp-parts/c-slot.svg',
    k: '/jp-parts/k-slot.svg',
    p: '/jp-parts/p-slot.svg',
    o: '/jp-parts/o-slot.svg',
    t: '/jp-parts/t-slot.svg',
    cherry: '/jp-parts/cherry-slot.svg',
    diamond: '/jp-parts/diamond-slot.svg',
    seven: '/jp-parts/seven-slot.svg',
}

const SYM_KEYS = Object.keys(SYMBOLS)
const JACKPOT = [['j', 'p'], ['a', 'o'], ['c', 't'], ['k', 'cherry']]
const NUM_COLS = JACKPOT.length
const DECOY_COUNT = 8

const pick = () => SYM_KEYS[Math.floor(Math.random() * SYM_KEYS.length)]

const PARTICLE_PALETTE = ['#a78bfa', '#818cf8', '#c084fc', '#e9d5ff', '#f0abfc', '#fff']

function buildReels(doJackpot) {
    if (doJackpot) {
        return JACKPOT.map(([wT, wB]) => [...Array.from({ length: DECOY_COUNT }, pick), wT, wB])
    }
    return JACKPOT.map(() => [...Array.from({ length: DECOY_COUNT }, pick), pick(), pick()])
}

function makeParticles(count = 28) {
    return Array.from({ length: count }, () => {
        const angle = Math.random() * Math.PI * 2
        const dist = 20 + Math.random() * 100
        return {
            x: Math.cos(angle) * dist,
            y: Math.sin(angle) * dist - 15,
            size: 2 + Math.random() * 3,
            color: PARTICLE_PALETTE[Math.floor(Math.random() * PARTICLE_PALETTE.length)],
            delay: Math.random() * 200,
            dur: 500 + Math.random() * 400,
        }
    })
}

const SPIN_DUR = 1400
const STAGGER = 160
const PAUSE = 1800
const VBLUR_PX = 10
const CELEBRATE_MS = 1600

const STYLES = `
@keyframes srl-scroll {
    0% {
        transform: translateY(var(--srl-end));
        animation-timing-function: cubic-bezier(0.33, 0, 0.9, 1);
    }
    7% {
        transform: translateY(0px);
        animation-timing-function: cubic-bezier(0.05, 0, 0.12, 1);
    }
    93% {
        transform: translateY(calc(var(--srl-end) + 4px));
        animation-timing-function: ease-out;
    }
    100% {
        transform: translateY(var(--srl-end));
    }
}
@keyframes srl-sparkle {
    0%   { transform: translate(-50%,-50%) scale(0); opacity: 1; }
    30%  { opacity: 1; }
    100% { transform: translate(calc(-50% + var(--sx)), calc(-50% + var(--sy))) scale(1); opacity: 0; }
}
@keyframes srl-glow {
    0%   { box-shadow: 0 0 0 0 rgba(139,92,246,0); }
    20%  { box-shadow: 0 0 18px 4px rgba(139,92,246,0.45), 0 0 36px 10px rgba(192,132,252,0.18); }
    50%  { box-shadow: 0 0 12px 3px rgba(139,92,246,0.3), 0 0 28px 7px rgba(192,132,252,0.12); }
    100% { box-shadow: 0 0 0 0 rgba(139,92,246,0); }
}
@keyframes srl-bounce {
    0%   { transform: scale(1); }
    15%  { transform: scale(1.06); }
    35%  { transform: scale(0.97); }
    55%  { transform: scale(1.02); }
    100% { transform: scale(1); }
}
@media (prefers-reduced-motion: reduce) {
    .srl-strip { animation: none !important; }
}`

/**
 * @param {Object}  props
 * @param {boolean} props.landed    - true → snap to JACKPOT + celebrate
 * @param {string}  props.size      - 'sm' | 'md' | 'lg'
 * @param {string}  props.className - extra wrapper classes
 * @param {string}  props.label     - optional text below the reels
 */
export default function SlotReelLoader({ landed = false, size = 'md', className = '', label }) {
    const outerRef = useRef(null)
    const blurEls = useRef([])
    const [reelH, setReelH] = useState(0)
    const [cycle, setCycle] = useState(0)
    const [celebrating, setCelebrating] = useState(false)
    const [celebKey, setCelebKey] = useState(0)
    const landedCount = useRef(0)
    const timer = useRef(null)
    const seqTimers = useRef([])
    const blurTimers = useRef([])
    const stoppedRef = useRef(false)

    const isJackpot = landed
    const reels = useMemo(() => buildReels(isJackpot), [cycle, isJackpot])
    const particles = useMemo(() => (celebrating ? makeParticles() : []), [celebKey])

    const heights = { sm: 'h-10', md: 'h-14', lg: 'h-20' }

    useEffect(() => {
        if (landed) stoppedRef.current = true
    }, [landed])

    useEffect(() => {
        landedCount.current = 0
        setCelebrating(false)
        seqTimers.current.forEach(clearTimeout)
        seqTimers.current = []
    }, [cycle])

    useEffect(() => {
        const el = outerRef.current
        if (!el) return
        const ro = new ResizeObserver(() => setReelH(el.offsetHeight))
        ro.observe(el)
        return () => ro.disconnect()
    }, [])

    useEffect(() => {
        Object.values(SYMBOLS).forEach((src) => { new Image().src = src })
    }, [])

    useEffect(() => {
        blurTimers.current.forEach(clearTimeout)
        blurTimers.current = []
        for (let col = 0; col < NUM_COLS; col++) {
            const delay = col * STAGGER
            blurTimers.current.push(
                setTimeout(() => blurEls.current[col]?.setAttribute('stdDeviation', `0 ${VBLUR_PX}`), delay + SPIN_DUR * 0.08),
                setTimeout(() => blurEls.current[col]?.setAttribute('stdDeviation', '0 0'), delay + SPIN_DUR * 0.84),
            )
        }
        return () => blurTimers.current.forEach(clearTimeout)
    }, [cycle])

    const onReelLand = useCallback(() => {
        landedCount.current++
        if (landedCount.current < NUM_COLS) return

        if (isJackpot) {
            setCelebrating(true)
            setCelebKey((k) => k + 1)
            seqTimers.current.push(setTimeout(() => setCelebrating(false), CELEBRATE_MS))
        } else if (!stoppedRef.current) {
            timer.current = setTimeout(() => setCycle((c) => c + 1), PAUSE)
        }
    }, [isJackpot])

    useEffect(() => () => {
        clearTimeout(timer.current)
        seqTimers.current.forEach(clearTimeout)
        blurTimers.current.forEach(clearTimeout)
    }, [])

    const cellH = reelH / 2
    const symCount = reels[0]?.length ?? DECOY_COUNT + 2
    const targetY = -((symCount - 2) * cellH)
    const cellW = cellH * (95 / 108.25)

    return (
        <div className={`flex flex-col items-center gap-3 ${className}`}>
            <style>{STYLES}</style>

            <svg width="0" height="0" aria-hidden="true" style={{ position: 'absolute' }}>
                <defs>
                    {Array.from({ length: NUM_COLS }, (_, i) => (
                        <filter key={i} id={`srl-b${i}-${cycle}`} x="-2%" y="-50%" width="104%" height="200%">
                            <feGaussianBlur
                                ref={(el) => (blurEls.current[i] = el)}
                                in="SourceGraphic"
                                stdDeviation="0 0"
                            />
                        </filter>
                    ))}
                </defs>
            </svg>

            <div
                className="relative"
                style={{ animation: celebrating ? `srl-bounce ${CELEBRATE_MS}ms ease` : 'none' }}
            >
                <div
                    ref={outerRef}
                    className={`flex items-stretch justify-center gap-1 sm:gap-1.5 ${heights[size] || heights.md} ${reelH === 0 ? 'invisible' : ''}`}
                    role="img"
                    aria-label="Processing"
                >
                    {reelH > 0 &&
                        reels.map((reel, col) => {
                            const delay = col * STAGGER
                            return (
                                <div
                                    key={col}
                                    className="overflow-hidden rounded-md"
                                    style={{
                                        width: cellW,
                                        background: '#fff',
                                        animation: celebrating ? `srl-glow ${CELEBRATE_MS}ms ease ${col * 80}ms` : 'none',
                                    }}
                                >
                                    <div
                                        key={`s${col}-${cycle}`}
                                        className="srl-strip flex flex-col"
                                        style={{
                                            '--srl-end': `${targetY}px`,
                                            transform: `translateY(${targetY}px)`,
                                            willChange: 'transform',
                                            animation: `srl-scroll ${SPIN_DUR}ms ${delay}ms both`,
                                            filter: `url(#srl-b${col}-${cycle})`,
                                        }}
                                        onAnimationEnd={onReelLand}
                                    >
                                        {reel.map((sym, i) => (
                                            <div
                                                key={i}
                                                className="flex shrink-0 items-center justify-center"
                                                style={{ height: cellH, padding: '12%' }}
                                            >
                                                <img
                                                    src={SYMBOLS[sym]}
                                                    alt=""
                                                    className="h-full w-full object-contain select-none pointer-events-none invert"
                                                    draggable={false}
                                                />
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )
                        })}
                </div>

                {celebrating && (
                    <div className="absolute inset-0 pointer-events-none overflow-visible" aria-hidden="true">
                        {particles.map((p, i) => (
                            <div
                                key={`${celebKey}-${i}`}
                                className="absolute left-1/2 top-1/2 rounded-full"
                                style={{
                                    width: p.size,
                                    height: p.size,
                                    background: p.color,
                                    '--sx': `${p.x}px`,
                                    '--sy': `${p.y}px`,
                                    animation: `srl-sparkle ${p.dur}ms ${p.delay}ms ease-out forwards`,
                                    opacity: 0,
                                }}
                            />
                        ))}
                    </div>
                )}
            </div>

            {label && (
                <p className="text-xs text-white/40 text-center leading-snug max-w-[200px]">{label}</p>
            )}
        </div>
    )
}
