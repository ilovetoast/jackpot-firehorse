import type { CSSProperties } from 'react'
import { gridLinePositions, type GridDensity, type SnapHit } from '../../utils/snapEngine'

type GridOverlayProps = {
    /** Document-space width (px). Used only to compute aspect for the SVG viewBox. */
    docW: number
    /** Document-space height (px). */
    docH: number
    /** 3 / 6 / 12. */
    density: GridDensity
    /** Most recent snap hit(s) to flash briefly. */
    hits?: SnapHit[]
    /** Optional extra class on the wrapper. */
    className?: string
}

/**
 * Non-interactive SVG grid overlay drawn on top of the stage. The wrapper is
 * `pointer-events: none` so layer drag/selection is unaffected. When snap
 * fires we highlight the matched line(s) with a brighter stroke — caller
 * resets `hits` after ~150ms to make it flash.
 */
export default function GridOverlay({ docW, docH, density, hits = [], className }: GridOverlayProps) {
    const vLines = gridLinePositions(docW, density)
    const hLines = gridLinePositions(docH, density)

    const style: CSSProperties = {
        position: 'absolute',
        inset: 0,
        width: '100%',
        height: '100%',
        pointerEvents: 'none',
        zIndex: 12,
    }

    const isHit = (kind: 'v' | 'h', at: number) =>
        hits.some((h) => h.kind === kind && Math.abs(h.at - at) < 0.5)

    return (
        <svg
            data-jp-export-capture-exclude
            aria-hidden="true"
            className={className}
            style={style}
            viewBox={`0 0 ${docW} ${docH}`}
            preserveAspectRatio="none"
        >
            {vLines.map((x) => (
                <line
                    key={`v-${x}`}
                    x1={x}
                    x2={x}
                    y1={0}
                    y2={docH}
                    stroke={isHit('v', x) ? 'rgba(99,102,241,0.95)' : 'rgba(255,255,255,0.22)'}
                    strokeWidth={isHit('v', x) ? 2 : 1}
                    vectorEffect="non-scaling-stroke"
                />
            ))}
            {hLines.map((y) => (
                <line
                    key={`h-${y}`}
                    x1={0}
                    x2={docW}
                    y1={y}
                    y2={y}
                    stroke={isHit('h', y) ? 'rgba(99,102,241,0.95)' : 'rgba(255,255,255,0.22)'}
                    strokeWidth={isHit('h', y) ? 2 : 1}
                    vectorEffect="non-scaling-stroke"
                />
            ))}
        </svg>
    )
}
