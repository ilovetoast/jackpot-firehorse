import { useEffect, useMemo, useCallback } from 'react'
import { motion, useAnimation, useReducedMotion } from 'framer-motion'

/**
 * J · 🍒 · P slot rails (white SVGs on black), with neighbour symbols bleeding
 * top/bottom like the Jackpot / apple-touch icon. Hover: spin and land on three
 * cherries; leave: return to J / cherry / P.
 */
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

const REEL_CONFIG = [
    { keys: ['seven', 'j', 'a', 'p', 'diamond', 'cherry', 'a', 'seven'], rest: 1, win: 5 },
    { keys: ['seven', 'a', 'p', 'j', 'cherry', 'a', 'p', 'cherry', 'j'], rest: 4, win: 7 },
    { keys: ['a', 'p', 'seven', 'p', 'a', 'j', 'cherry', 'a'], rest: 3, win: 6 },
]

/** One row in the virtual strip; scales the whole mark. */
const SYM_H = 32
/**
 * Taller than one row: (VIEW_H − SYM_H) / 2 is how much of the prev/next row shows
 * above/below the center (apple-touch / slot-machine “offset” look).
 * ~12px per side at these numbers — visibly more than the old tight crop.
 */
const VIEW_H = 56
const CELL_W = Math.round((SYM_H * 95) / 108.25 * 10) / 10
const STRIP_NUDGE_Y = 2

function yForIndex(i) {
    const mid = VIEW_H / 2
    return mid - SYM_H * (i + 0.5) + STRIP_NUDGE_Y
}

/**
 * @param {Object} props
 * @param {string} [props.className]
 * @param {string} [props.label]   - aria label when not decorative
 * @param {boolean} [props.decorative] - hide from assitive tech; use beside explanatory copy
 */
export default function JackpotSlotReels({ className = '', label = 'Jackpot', decorative = false }) {
    const reduce = useReducedMotion()
    const c0 = useAnimation()
    const c1 = useAnimation()
    const c2 = useAnimation()
    const controls = [c0, c1, c2]

    const yRest = useMemo(() => REEL_CONFIG.map((c) => yForIndex(c.rest)), [])
    const yWin = useMemo(() => REEL_CONFIG.map((c) => yForIndex(c.win)), [])

    useEffect(() => {
        Object.values(SYMBOLS).forEach((src) => {
            const img = new Image()
            img.src = src
        })
    }, [])

    const onEnter = useCallback(async () => {
        if (reduce) {
            await Promise.all(controls.map((c, i) => c.start({ y: yWin[i] })))
            return
        }
        const spin = (rest, win) => {
            const travel = Math.round(52 * (SYM_H / 20))
            const settle = Math.round(6 * (SYM_H / 20))
            return [rest, rest - travel, rest - travel * 2.2, win - settle, win]
        }
        await Promise.all(
            controls.map((c, i) =>
                c.start({
                    y: spin(yRest[i], yWin[i]),
                    transition: {
                        duration: 1.1,
                        times: [0, 0.12, 0.38, 0.78, 1],
                        ease: 'easeInOut',
                        delay: i * 0.07,
                    },
                }),
            ),
        )
    }, [c0, c1, c2, reduce, yRest, yWin])

    const onLeave = useCallback(() => {
        controls.forEach((c, i) => {
            c.start({
                y: yRest[i],
                transition: { duration: reduce ? 0.15 : 0.5, ease: 'easeOut' },
            })
        })
    }, [c0, c1, c2, reduce, yRest])

    return (
        <div
            className={`inline-flex select-none items-end justify-center gap-0.5 ${className}`.trim()}
            role={decorative ? 'presentation' : 'img'}
            aria-label={decorative ? undefined : label}
            aria-hidden={decorative || undefined}
            onMouseEnter={onEnter}
            onMouseLeave={onLeave}
        >
            {REEL_CONFIG.map((reel, col) => (
                <div
                    key={col}
                    className="relative shrink-0 overflow-hidden rounded-lg bg-gray-950 px-1 ring-1 ring-white/10"
                    style={{ width: CELL_W, height: VIEW_H }}
                >
                    <motion.div
                        className="flex flex-col will-change-transform"
                        initial={{ y: yRest[col] }}
                        animate={controls[col]}
                    >
                        {reel.keys.map((key, i) => (
                            <div
                                key={`${col}-${i}-${key}`}
                                className="flex shrink-0 items-center justify-center"
                                style={{
                                    height: SYM_H,
                                    width: '100%',
                                    /* Slightly looser so the mark fills the row; neighbours still read as strips */
                                    padding: '5% 7%',
                                }}
                            >
                                <img
                                    src={SYMBOLS[key]}
                                    alt=""
                                    className="h-full w-full object-contain"
                                    draggable={false}
                                />
                            </div>
                        ))}
                    </motion.div>
                </div>
            ))}
        </div>
    )
}
