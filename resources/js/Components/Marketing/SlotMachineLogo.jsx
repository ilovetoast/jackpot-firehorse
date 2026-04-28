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
const NON_CHERRY_KEYS = SYM_KEYS.filter(k => k !== 'cherry')
const JACKPOT = [['j', 'p'], ['a', 'o'], ['c', 't'], ['k', 'cherry']]
const NUM_COLS = JACKPOT.length
const DECOY_COUNT = 10

const pick = () => SYM_KEYS[Math.floor(Math.random() * SYM_KEYS.length)]
const pickNoCherry = () => NON_CHERRY_KEYS[Math.floor(Math.random() * NON_CHERRY_KEYS.length)]

// Site brand colors (hardcoded so tenant theming never overrides the logo animation).
const SITE_PRIMARY = '#7c3aed'
const SITE_PRIMARY_RGB = '124,58,217'

const PARTICLE_PALETTE = ['#8b5cf6', '#a78bfa', '#c4b5fd', '#7c3aed', '#5b21b6', '#fff']

/**
 * Cycle sequence on the homepage:
 *   cycle 0 (mod 0) → 'random'   : intentionally incorrect spin – centered in the middle,
 *                                   the reels come to rest staggered/offset.
 *   cycle 1 (mod 1) → 'cherries' : success state – all cherries centered vertically,
 *                                   cells blink site-primary (violet) with fireworks.
 *   cycle 2 (mod 2) → 'jackpot'  : final resting pose – JACKPOT text, reels perfectly aligned.
 *
 * The first three spins play automatically as an intro. After cycle 2 the reels stop
 * and only a user "pull" (mousedown → mouseup) advances to the next cycle.
 */
function spinMode(cycle) {
    const mod = cycle % 3
    if (mod === 0) return 'random'
    if (mod === 1) return 'cherries'
    return 'jackpot'
}

function buildReels(mode) {
    if (mode === 'jackpot') {
        // Bracketed with 'blank' rows so the strip can be shifted up/down a few px
        // at rest and show empty white space at the cropped edge (instead of a decoy).
        return JACKPOT.map(([wT, wB]) => [
            ...Array.from({ length: DECOY_COUNT - 1 }, pick),
            'blank',
            wT,
            wB,
            'blank',
        ])
    }
    if (mode === 'cherries') {
        // Reel of length DECOY_COUNT + 3 with 'cherry' at index DECOY_COUNT + 1.
        // Paired with a custom targetY the cherry lands centered in the viewport
        // and the surrounding decoys are cropped at the top & bottom edges.
        return JACKPOT.map(() => [
            ...Array.from({ length: DECOY_COUNT + 1 }, pickNoCherry),
            'cherry',
            pickNoCherry(),
        ])
    }
    // 'random' – same centered structure as cherries so a single (non-matching)
    // symbol lands in the vertical middle with decoys cropped above/below.
    return JACKPOT.map(() => [
        ...Array.from({ length: DECOY_COUNT + 1 }, pickNoCherry),
        pickNoCherry(),
        pickNoCherry(),
    ])
}

function makeParticles(count = 56) {
    return Array.from({ length: count }, () => {
        const angle = Math.random() * Math.PI * 2
        const dist = 40 + Math.random() * 220
        const isLarge = Math.random() > 0.65
        return {
            x: Math.cos(angle) * dist,
            y: Math.sin(angle) * dist - 30,
            size: isLarge ? 4.5 + Math.random() * 4 : 2 + Math.random() * 3,
            color: PARTICLE_PALETTE[Math.floor(Math.random() * PARTICLE_PALETTE.length)],
            delay: Math.random() * 320,
            dur: 700 + Math.random() * 700,
        }
    })
}

const FIRST_DUR    = 2600
const FAST_DUR     = 1600
const STAGGER      = 220
const FIRST_WAIT   = 700
const VBLUR_PX     = 14

const BETWEEN_INTRO = 1400  // gap between cycle 0 → 1 and 1 → 2 during the intro
const CELEBRATE_MS  = 2200  // full cherry-celebration duration
const SETTLE_MS     = 350   // brief hold before reels relax into offsets

// Staggered per-column offsets applied to the slot *frames* for the "incorrect
// random" resting pose and for the visual response when the user pulls the lever.
const REEL_OFFSETS = [-6, 5, -3, 8]
// Jackpot rest: how far each reel's strip content is shifted vertically inside
// its (aligned) slot frame. Positive = content slides DOWN (top crops to white,
// bottom content pushes past the slot); negative = content slides UP (top clipped).
//   col 0 (J/P)        : P lower / J more centered, P cut at bottom
//   col 1 (A/O)        : A cut at top
//   col 2 (C/T)        : C lower / T cut at bottom
//   col 3 (K/cherry)   : K cut at top
const JACKPOT_STRIP_OFFSETS = [20, -15, 15, -22]
const HOVER_LIFT   = [-2, -3, -2, -4]   // spring-loaded: lift UP on hover

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
@keyframes cherry-cell-blink {
    0%, 100% {
        background: #fff;
        box-shadow: 0 0 0 0 rgba(${SITE_PRIMARY_RGB}, 0);
    }
    25% {
        background: ${SITE_PRIMARY};
        box-shadow: 0 0 28px 6px rgba(${SITE_PRIMARY_RGB}, 0.55), 0 0 56px 14px rgba(${SITE_PRIMARY_RGB}, 0.25);
    }
    50% {
        background: #fff;
        box-shadow: 0 0 22px 5px rgba(${SITE_PRIMARY_RGB}, 0.35);
    }
    75% {
        background: ${SITE_PRIMARY};
        box-shadow: 0 0 30px 8px rgba(${SITE_PRIMARY_RGB}, 0.55), 0 0 60px 16px rgba(${SITE_PRIMARY_RGB}, 0.25);
    }
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
    .slot-cell  { animation: none !important; }
}`

export default function SlotMachineLogo({ className = '' }) {
    const outerRef = useRef(null)
    const blurEls = useRef([])
    const [reelH, setReelH] = useState(0)
    const [cycle, setCycle] = useState(0)
    const [interactive, setInteractive] = useState(false)
    const [mouse, setMouse] = useState('idle')       // idle | hovering | pulling
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
    const isCherries = mode === 'cherries'
    const reels = useMemo(() => buildReels(mode), [cycle, mode])
    const dur = cycle === 0 ? FIRST_DUR : FAST_DUR

    const particles = useMemo(() => celebrating ? makeParticles() : [], [celebKey])

    // During the intro (cycles 0 → 1 → 2) the reels auto-advance.
    // After the jackpot-rest pose is reached, only a user "pull" advances the cycle.
    const isIntro = cycle < 2

    useEffect(() => {
        landed.current = 0
        spinning.current = true
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
        if (landed.current < NUM_COLS) return

        spinning.current = false
        if (!interactive) setInteractive(true)

        if (isCherries) {
            // Big success moment: cherries blink purple & fireworks fly out.
            seqTimers.current.push(
                setTimeout(() => {
                    setCelebrating(true)
                    setCelebKey(k => k + 1)
                }, SETTLE_MS),
                setTimeout(() => setCelebrating(false), SETTLE_MS + CELEBRATE_MS),
            )
            if (isIntro && !hovering.current) {
                timer.current = setTimeout(
                    () => setCycle(c => c + 1),
                    SETTLE_MS + CELEBRATE_MS + 400,
                )
            }
            return
        }

        if (isJackpot) {
            // Final resting pose – reels stay aligned (translateY 0) and wait for a user pull.
            return
        }

        // 'random' – auto-advance only during intro.
        if (isIntro && !hovering.current) {
            timer.current = setTimeout(() => setCycle(c => c + 1), BETWEEN_INTRO)
        }
    }, [interactive, isCherries, isJackpot, isIntro])

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
        // Intentionally no auto-respin on leave once the intro has finished.
        // The user must pull the lever to trigger the next spin.
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
    // Jackpot reels have a trailing 'blank' buffer cell, so the winning pair
    // (wT, wB) sits at indices symCount-3 / symCount-2. Random & cherries center
    // a single symbol in the viewport with neighbours cropped top & bottom.
    const isCentered = isCherries || mode === 'random'
    const baseTargetY = isJackpot
        ? -((symCount - 3) * cellH)
        : isCentered
            ? -((symCount - 2.5) * cellH)
            : -((symCount - 2) * cellH)
    // For jackpot rest the slot frames stay aligned, but the strip inside each reel
    // lands shifted a few px so the J-A-C-K / P-O-T-cherry content is staggered
    // within the frames — matching the flat JACKPOT logo.
    const getTargetY = (col) => (isJackpot ? baseTargetY + JACKPOT_STRIP_OFFSETS[col] : baseTargetY)
    const cellW = cellH * (95 / 108.25)

    const getReelY = (col) => {
        // Pulling the lever staggers the reels into the logo-style offset pose.
        if (mouse === 'pulling' && !spinning.current) return REEL_OFFSETS[col]
        if (mouse === 'hovering' && !spinning.current) return HOVER_LIFT[col]
        // The "incorrect" random result rests with its reels intentionally offset.
        if (mode === 'random') return REEL_OFFSETS[col]
        // Cherries success & jackpot final state: perfectly aligned.
        return 0
    }

    const getReelTransition = () => {
        if (mouse === 'pulling') return 'transform 120ms ease-out'
        return `transform ${SETTLE_MS}ms cubic-bezier(0.22, 0, 0, 1.12)`
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
                        const tY = getTargetY(col)

                        return (
                            <div
                                key={col}
                                className="slot-cell overflow-hidden rounded-lg"
                                style={{
                                    width: cellW,
                                    background: '#fff',
                                    transform: `translateY(${reelY}px)`,
                                    transition: getReelTransition(),
                                    animation: celebrating && isCherries
                                        ? `cherry-cell-blink ${CELEBRATE_MS}ms ease ${col * 90}ms`
                                        : 'none',
                                }}
                            >
                                <div
                                    key={`s${col}-${cycle}`}
                                    className="slot-strip flex flex-col"
                                    style={{
                                        '--slot-end': `${tY}px`,
                                        transform: `translateY(${tY}px)`,
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
                                            {sym !== 'blank' && (
                                                <img
                                                    src={SYMBOLS[sym]}
                                                    alt=""
                                                    className="h-full w-full object-contain select-none pointer-events-none invert"
                                                    draggable={false}
                                                />
                                            )}
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
