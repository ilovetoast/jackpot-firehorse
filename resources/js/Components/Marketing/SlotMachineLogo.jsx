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
const DECOY_COUNT = 10

const pick = () => SYM_KEYS[Math.floor(Math.random() * SYM_KEYS.length)]

const PARTICLE_PALETTE = ['#a78bfa', '#818cf8', '#c084fc', '#e9d5ff', '#f0abfc', '#fff']

function spinMode(cycle) {
    if (cycle % 3 === 0) return 'jackpot'
    if (cycle % 3 === 2) return 'near-miss'
    return 'random'
}

function buildReels(mode) {
    if (mode === 'jackpot') {
        return JACKPOT.map(([wT, wB]) => [...Array.from({ length: DECOY_COUNT }, pick), wT, wB])
    }
    if (mode === 'near-miss') {
        const missCol = Math.floor(Math.random() * NUM_COLS)
        return JACKPOT.map(([wT, wB], i) => {
            const decoys = Array.from({ length: DECOY_COUNT }, pick)
            return i === missCol ? [...decoys, pick(), pick()] : [...decoys, wT, wB]
        })
    }
    return JACKPOT.map(() => [...Array.from({ length: DECOY_COUNT }, pick), pick(), pick()])
}

function makeParticles(count = 44) {
    return Array.from({ length: count }, () => {
        const angle = Math.random() * Math.PI * 2
        const dist = 40 + Math.random() * 200
        const isLarge = Math.random() > 0.65
        return {
            x: Math.cos(angle) * dist,
            y: Math.sin(angle) * dist - 30,
            size: isLarge ? 4.5 + Math.random() * 4 : 2 + Math.random() * 3,
            color: PARTICLE_PALETTE[Math.floor(Math.random() * PARTICLE_PALETTE.length)],
            delay: Math.random() * 280,
            dur: 700 + Math.random() * 600,
        }
    })
}

const FIRST_DUR    = 2600
const FAST_DUR     = 1600
const STAGGER      = 220
const PAUSE        = 2000
const FIRST_WAIT   = 700
const VBLUR_PX     = 14

const PRE_SNAP_HOLD = 1200
const SNAP_DUR      = 350
const CELEBRATE_MS  = 2200
const POST_CELEB    = 800

const REEL_OFFSETS = [-6, 5, -3, 8]
const HOVER_LIFT   = [-2, -3, -2, -4]   // spring-loaded: lift UP on hover
const PULL_DOWN    = [5, 8, 6, 10]      // lever pull: shift DOWN on mousedown

/**
 * Interaction states:
 *  'idle'     → auto-spinning, no mouse nearby
 *  'hovering' → mouse over, reels stopped, lifted up (spring-loaded)
 *  'pulling'  → mousedown, reels pulled down (lever)
 */

const STYLES = `
@keyframes slot-scroll {
    0% {
        transform: translateY(var(--slot-end));
        animation-timing-function: cubic-bezier(0.33, 0, 0.9, 1);
    }
    7% {
        transform: translateY(0px);
        animation-timing-function: cubic-bezier(0.05, 0, 0.12, 1);
    }
    93% {
        transform: translateY(calc(var(--slot-end) + 6px));
        animation-timing-function: ease-out;
    }
    100% {
        transform: translateY(var(--slot-end));
    }
}
@keyframes sparkle-fly {
    0%   { transform: translate(-50%,-50%) scale(0); opacity: 1; }
    30%  { opacity: 1; }
    100% { transform: translate(calc(-50% + var(--sx)), calc(-50% + var(--sy))) scale(1); opacity: 0; }
}
@keyframes jackpot-glow {
    0%   { box-shadow: 0 0 0 0 rgba(139,92,246,0); }
    20%  { box-shadow: 0 0 30px 8px rgba(139,92,246,0.5), 0 0 60px 16px rgba(192,132,252,0.2); }
    50%  { box-shadow: 0 0 22px 5px rgba(139,92,246,0.35), 0 0 44px 10px rgba(192,132,252,0.15); }
    100% { box-shadow: 0 0 0 0 rgba(139,92,246,0); }
}
@keyframes jackpot-bounce {
    0%   { transform: scale(1); }
    15%  { transform: scale(1.08); }
    35%  { transform: scale(0.96); }
    55%  { transform: scale(1.03); }
    75%  { transform: scale(0.99); }
    100% { transform: scale(1); }
}
@media (prefers-reduced-motion: reduce) {
    .slot-strip { animation: none !important; }
}`

export default function SlotMachineLogo({ className = '' }) {
    const outerRef = useRef(null)
    const blurEls = useRef([])
    const [reelH, setReelH] = useState(0)
    const [cycle, setCycle] = useState(0)
    const [interactive, setInteractive] = useState(false)
    const [mouse, setMouse] = useState('idle')       // idle | hovering | pulling
    const [preSnap, setPreSnap] = useState(false)
    const [celebrating, setCelebrating] = useState(false)
    const [celebKey, setCelebKey] = useState(0)
    const landed = useRef(0)
    const timer = useRef(null)
    const seqTimers = useRef([])
    const blurTimers = useRef([])
    const hovering = useRef(false)
    const spinning = useRef(true)

    const mode = spinMode(cycle)
    const isJackpot = mode === 'jackpot'
    const reels = useMemo(() => buildReels(mode), [cycle, mode])
    const dur = cycle === 0 ? FIRST_DUR : FAST_DUR

    const particles = useMemo(() => celebrating ? makeParticles() : [], [celebKey])

    useEffect(() => {
        landed.current = 0
        spinning.current = true
        setPreSnap(isJackpot)
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
        Object.values(SYMBOLS).forEach(src => { new Image().src = src })
    }, [])

    useEffect(() => {
        blurTimers.current.forEach(clearTimeout)
        blurTimers.current = []
        for (let col = 0; col < NUM_COLS; col++) {
            const delay = (cycle === 0 ? FIRST_WAIT : 0) + col * STAGGER
            blurTimers.current.push(
                setTimeout(() => blurEls.current[col]?.setAttribute('stdDeviation', `0 ${VBLUR_PX}`), delay + dur * 0.08),
                setTimeout(() => blurEls.current[col]?.setAttribute('stdDeviation', '0 0'), delay + dur * 0.84),
            )
        }
        return () => blurTimers.current.forEach(clearTimeout)
    }, [cycle, dur])

    const onReelLand = useCallback(() => {
        landed.current++
        if (landed.current >= NUM_COLS) {
            spinning.current = false
            if (!interactive) setInteractive(true)

            if (isJackpot) {
                seqTimers.current.push(
                    setTimeout(() => setPreSnap(false), PRE_SNAP_HOLD),
                    setTimeout(() => {
                        setCelebrating(true)
                        setCelebKey(k => k + 1)
                    }, PRE_SNAP_HOLD + SNAP_DUR),
                    setTimeout(() => setCelebrating(false), PRE_SNAP_HOLD + SNAP_DUR + CELEBRATE_MS),
                )
                if (!hovering.current) {
                    timer.current = setTimeout(
                        () => setCycle(c => c + 1),
                        PRE_SNAP_HOLD + SNAP_DUR + CELEBRATE_MS + POST_CELEB,
                    )
                }
            } else {
                if (!hovering.current) {
                    timer.current = setTimeout(() => setCycle(c => c + 1), PAUSE)
                }
            }
        }
    }, [interactive, isJackpot])

    // --- Interaction handlers ---

    const onMouseEnter = useCallback(() => {
        if (!interactive) return
        hovering.current = true
        setMouse('hovering')
        clearTimeout(timer.current)
    }, [interactive])

    const onMouseLeave = useCallback(() => {
        if (!interactive) return
        hovering.current = false
        setMouse('idle')
        if (!spinning.current) {
            timer.current = setTimeout(() => setCycle(c => c + 1), 600)
        }
    }, [interactive])

    const onMouseDown = useCallback((e) => {
        if (!interactive || spinning.current) return
        e.preventDefault()
        setMouse('pulling')
    }, [interactive])

    const onMouseUp = useCallback(() => {
        if (!interactive || mouse !== 'pulling') return
        setMouse('idle')
        hovering.current = false
        clearTimeout(timer.current)
        setCycle(c => c + 1)
    }, [interactive, mouse])

    // Handle mouseup outside the component (user drags out while holding)
    useEffect(() => {
        if (mouse !== 'pulling') return
        const handleGlobalUp = () => {
            setMouse('idle')
            hovering.current = false
            clearTimeout(timer.current)
            setCycle(c => c + 1)
        }
        window.addEventListener('mouseup', handleGlobalUp)
        return () => window.removeEventListener('mouseup', handleGlobalUp)
    }, [mouse])

    useEffect(() => () => {
        clearTimeout(timer.current)
        seqTimers.current.forEach(clearTimeout)
        blurTimers.current.forEach(clearTimeout)
    }, [])

    const cellH = reelH / 2
    const symCount = reels[0]?.length ?? DECOY_COUNT + 2
    const targetY = -((symCount - 2) * cellH)
    const cellW = cellH * (95 / 108.25)

    const getReelY = (col) => {
        if (isJackpot && preSnap) return REEL_OFFSETS[col]
        if (mouse === 'pulling' && !spinning.current) return PULL_DOWN[col]
        if (mouse === 'hovering' && !spinning.current) return HOVER_LIFT[col]
        return 0
    }

    const getReelTransition = () => {
        if (preSnap) return 'none'
        if (mouse === 'pulling') return 'transform 120ms ease-out'
        return `transform ${SNAP_DUR}ms cubic-bezier(0.22, 0, 0, 1.12)`
    }

    const cursor = interactive
        ? mouse === 'pulling' ? 'grabbing'
        : !spinning.current ? 'grab'
        : 'default'
        : undefined

    return (
        <>
            <style>{STYLES}</style>

            <svg width="0" height="0" aria-hidden="true" style={{ position: 'absolute' }}>
                <defs>
                    {Array.from({ length: NUM_COLS }, (_, i) => (
                        <filter key={i} id={`svb${i}`} x="-2%" y="-50%" width="104%" height="200%">
                            <feGaussianBlur
                                ref={el => (blurEls.current[i] = el)}
                                in="SourceGraphic"
                                stdDeviation="0 0"
                            />
                        </filter>
                    ))}
                </defs>
            </svg>

            <div
                className="relative"
                style={{ animation: celebrating ? `jackpot-bounce ${CELEBRATE_MS}ms ease` : 'none' }}
            >
                <div
                    ref={outerRef}
                    className={`flex items-stretch justify-center gap-1.5 sm:gap-2 lg:gap-2.5 ${reelH === 0 ? 'invisible' : ''} ${className}`}
                    role="img"
                    aria-label="Jackpot"
                    onMouseEnter={onMouseEnter}
                    onMouseLeave={onMouseLeave}
                    onMouseDown={onMouseDown}
                    onMouseUp={onMouseUp}
                    style={{ cursor, userSelect: 'none' }}
                >
                    {reelH > 0 && reels.map((reel, col) => {
                        const delay = (cycle === 0 ? FIRST_WAIT : 0) + col * STAGGER
                        const reelY = getReelY(col)

                        return (
                            <div
                                key={col}
                                className="overflow-hidden rounded-lg"
                                style={{
                                    width: cellW,
                                    background: '#fff',
                                    transform: `translateY(${reelY}px)`,
                                    transition: getReelTransition(),
                                    animation: celebrating ? `jackpot-glow ${CELEBRATE_MS}ms ease ${col * 100}ms` : 'none',
                                }}
                            >
                                <div
                                    key={`s${col}-${cycle}`}
                                    className="slot-strip flex flex-col"
                                    style={{
                                        '--slot-end': `${targetY}px`,
                                        transform: `translateY(${targetY}px)`,
                                        willChange: 'transform',
                                        animation: `slot-scroll ${dur}ms ${delay}ms both`,
                                        filter: `url(#svb${col})`,
                                    }}
                                    onAnimationEnd={onReelLand}
                                >
                                    {reel.map((sym, i) => (
                                        <div
                                            key={i}
                                            className="flex shrink-0 items-center justify-center"
                                            style={{ height: cellH, padding: '10%' }}
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
                                    animation: `sparkle-fly ${p.dur}ms ${p.delay}ms ease-out forwards`,
                                    opacity: 0,
                                }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </>
    )
}
