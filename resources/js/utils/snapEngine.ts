/**
 * Snap Engine — Studio Editor / Template Wizard
 *
 * Pure math utilities for a 3x3 / 6x6 / 12x12 grid-based snap system.
 * Callers supply document-space rectangles; helpers return snapped rectangles
 * plus optional "hit" info so the overlay can briefly highlight the line that
 * was matched.
 *
 * Two snap modes are supported:
 *  - `cell_center` (Basic): the element's CENTER snaps to the center of the
 *    nearest 1-of-N^2 grid cell. Pairs with the 9-slot Placement picker.
 *  - `line_align`  (Advanced): the element's edges AND center axes snap to
 *    the nearest grid LINE within `thresholdDoc` pixels. Rule-of-thirds style.
 *
 * `placementToXY()` is a shared helper used by the Placement picker UI so a
 * click on "top-center" (etc.) translates 1:1 to what a drag-to-snap would
 * produce.
 */

export type GridDensity = 3 | 6 | 12
export type SnapMode = 'cell_center' | 'line_align' | 'off'

export type Placement =
    | 'top_left'
    | 'top_center'
    | 'top_right'
    | 'middle_left'
    | 'middle_center'
    | 'middle_right'
    | 'bottom_left'
    | 'bottom_center'
    | 'bottom_right'

export const PLACEMENTS: Placement[] = [
    'top_left', 'top_center', 'top_right',
    'middle_left', 'middle_center', 'middle_right',
    'bottom_left', 'bottom_center', 'bottom_right',
]

export type Rect = {
    x: number
    y: number
    width: number
    height: number
}

export type CellAnchor = {
    row: number
    col: number
    cx: number
    cy: number
}

export type SnapHit = {
    kind: 'v' | 'h'
    at: number
}

export type MoveSnapResult = {
    x: number
    y: number
    hits: SnapHit[]
    cellRow?: number
    cellCol?: number
}

export type ResizeSnapResult = {
    x: number
    y: number
    width: number
    height: number
    hits: SnapHit[]
}

/** Computed cell center coordinates for a given grid density. */
export function computeCellAnchors(docW: number, docH: number, density: GridDensity): CellAnchor[] {
    const anchors: CellAnchor[] = []
    const cellW = docW / density
    const cellH = docH / density
    for (let row = 0; row < density; row++) {
        for (let col = 0; col < density; col++) {
            anchors.push({
                row,
                col,
                cx: cellW * (col + 0.5),
                cy: cellH * (row + 0.5),
            })
        }
    }
    return anchors
}

/** List of grid line positions (document-space) along one axis. Excludes 0 and the far edge. */
export function gridLinePositions(dim: number, density: GridDensity): number[] {
    const out: number[] = []
    for (let i = 1; i < density; i++) {
        out.push((dim * i) / density)
    }
    return out
}

/**
 * Snap an element's CENTER to the nearest cell center. Always snaps (no
 * threshold) — this is the Basic-mode contract: everything goes to a quadrant.
 */
export function snapPointToCellCenter(
    x: number,
    y: number,
    w: number,
    h: number,
    docW: number,
    docH: number,
    density: GridDensity,
): MoveSnapResult {
    const cellW = docW / density
    const cellH = docH / density
    const centerX = x + w / 2
    const centerY = y + h / 2
    const col = Math.max(0, Math.min(density - 1, Math.floor(centerX / cellW)))
    const row = Math.max(0, Math.min(density - 1, Math.floor(centerY / cellH)))
    const cx = cellW * (col + 0.5)
    const cy = cellH * (row + 0.5)
    return {
        x: cx - w / 2,
        y: cy - h / 2,
        cellRow: row,
        cellCol: col,
        hits: [
            { kind: 'v', at: cx },
            { kind: 'h', at: cy },
        ],
    }
}

/**
 * Snap a moving element's edges + center to the nearest grid lines within
 * `thresholdDoc` document-space pixels. Advanced-mode default.
 */
export function snapRectLineAlign(
    rect: Rect,
    docW: number,
    docH: number,
    density: GridDensity,
    thresholdDoc: number,
): MoveSnapResult {
    const vLines = [0, ...gridLinePositions(docW, density), docW]
    const hLines = [0, ...gridLinePositions(docH, density), docH]

    const vCandidates = [rect.x, rect.x + rect.width / 2, rect.x + rect.width]
    const hCandidates = [rect.y, rect.y + rect.height / 2, rect.y + rect.height]

    let bestDx = 0
    let bestDxAbs = thresholdDoc + 1
    let bestVHit: number | null = null
    for (const c of vCandidates) {
        for (const l of vLines) {
            const d = l - c
            const abs = Math.abs(d)
            if (abs < bestDxAbs) {
                bestDxAbs = abs
                bestDx = d
                bestVHit = l
            }
        }
    }
    if (bestDxAbs > thresholdDoc) {
        bestDx = 0
        bestVHit = null
    }

    let bestDy = 0
    let bestDyAbs = thresholdDoc + 1
    let bestHHit: number | null = null
    for (const c of hCandidates) {
        for (const l of hLines) {
            const d = l - c
            const abs = Math.abs(d)
            if (abs < bestDyAbs) {
                bestDyAbs = abs
                bestDy = d
                bestHHit = l
            }
        }
    }
    if (bestDyAbs > thresholdDoc) {
        bestDy = 0
        bestHHit = null
    }

    const hits: SnapHit[] = []
    if (bestVHit !== null) hits.push({ kind: 'v', at: bestVHit })
    if (bestHHit !== null) hits.push({ kind: 'h', at: bestHHit })

    return {
        x: rect.x + bestDx,
        y: rect.y + bestDy,
        hits,
    }
}

/**
 * Snap a resized rectangle's sides to the nearest grid lines. We only snap
 * the sides that actually moved (derived from `corner`), leaving the anchor
 * side alone so the opposite corner stays pinned.
 */
export function snapRectResizeLineAlign(
    rect: Rect,
    corner: 'nw' | 'ne' | 'sw' | 'se',
    docW: number,
    docH: number,
    density: GridDensity,
    thresholdDoc: number,
): ResizeSnapResult {
    const vLines = [0, ...gridLinePositions(docW, density), docW]
    const hLines = [0, ...gridLinePositions(docH, density), docH]

    const movesLeft = corner === 'nw' || corner === 'sw'
    const movesTop = corner === 'nw' || corner === 'ne'

    const hits: SnapHit[] = []
    let x = rect.x
    let y = rect.y
    let width = rect.width
    let height = rect.height

    const snapAxis = (target: number, lines: number[]) => {
        let best = 0
        let bestAbs = thresholdDoc + 1
        let hit: number | null = null
        for (const l of lines) {
            const d = l - target
            const abs = Math.abs(d)
            if (abs < bestAbs) {
                bestAbs = abs
                best = d
                hit = l
            }
        }
        return bestAbs <= thresholdDoc ? { delta: best, hit } : { delta: 0, hit: null }
    }

    if (movesLeft) {
        const { delta, hit } = snapAxis(x, vLines)
        if (hit !== null) {
            x += delta
            width -= delta
            hits.push({ kind: 'v', at: hit })
        }
    } else {
        const right = x + width
        const { delta, hit } = snapAxis(right, vLines)
        if (hit !== null) {
            width += delta
            hits.push({ kind: 'v', at: hit })
        }
    }

    if (movesTop) {
        const { delta, hit } = snapAxis(y, hLines)
        if (hit !== null) {
            y += delta
            height -= delta
            hits.push({ kind: 'h', at: hit })
        }
    } else {
        const bottom = y + height
        const { delta, hit } = snapAxis(bottom, hLines)
        if (hit !== null) {
            height += delta
            hits.push({ kind: 'h', at: hit })
        }
    }

    return { x, y, width: Math.max(1, width), height: Math.max(1, height), hits }
}

/**
 * Map a 9-slot Placement token to an (x, y) that centers a `w x h` layer in
 * the corresponding cell of a 3x3 grid. This is the bridge between the
 * Placement picker UI and the snap engine: clicking "top-center" is
 * equivalent to dragging a layer until it snaps into that quadrant.
 */
export function placementToXY(
    placement: Placement,
    layerW: number,
    layerH: number,
    docW: number,
    docH: number,
): { x: number; y: number } {
    const [rowToken, colToken] = splitPlacement(placement)
    const rowIndex = rowToken === 'top' ? 0 : rowToken === 'middle' ? 1 : 2
    const colIndex = colToken === 'left' ? 0 : colToken === 'center' ? 1 : 2
    const cellW = docW / 3
    const cellH = docH / 3
    const cx = cellW * (colIndex + 0.5)
    const cy = cellH * (rowIndex + 0.5)
    return {
        x: Math.round(cx - layerW / 2),
        y: Math.round(cy - layerH / 2),
    }
}

/** Inverse of placementToXY — pick the closest 3x3 cell for a given rect. */
export function xyToPlacement(
    x: number,
    y: number,
    layerW: number,
    layerH: number,
    docW: number,
    docH: number,
): Placement {
    const cx = x + layerW / 2
    const cy = y + layerH / 2
    const col = Math.max(0, Math.min(2, Math.floor((cx / docW) * 3)))
    const row = Math.max(0, Math.min(2, Math.floor((cy / docH) * 3)))
    const rowToken: 'top' | 'middle' | 'bottom' = row === 0 ? 'top' : row === 1 ? 'middle' : 'bottom'
    const colToken: 'left' | 'center' | 'right' = col === 0 ? 'left' : col === 1 ? 'center' : 'right'
    return `${rowToken}_${colToken}` as Placement
}

function splitPlacement(p: Placement): ['top' | 'middle' | 'bottom', 'left' | 'center' | 'right'] {
    const [r, c] = p.split('_') as ['top' | 'middle' | 'bottom', 'left' | 'center' | 'right']
    return [r, c]
}

/**
 * High-level entry point that chooses the right strategy for a MOVE drag.
 * Returns the input rect unchanged when snap is off.
 */
export function snapMove(params: {
    rect: Rect
    docW: number
    docH: number
    mode: SnapMode
    density: GridDensity
    thresholdDoc: number
}): MoveSnapResult {
    const { rect, docW, docH, mode, density, thresholdDoc } = params
    if (mode === 'off') {
        return { x: rect.x, y: rect.y, hits: [] }
    }
    if (mode === 'cell_center') {
        return snapPointToCellCenter(rect.x, rect.y, rect.width, rect.height, docW, docH, density)
    }
    return snapRectLineAlign(rect, docW, docH, density, thresholdDoc)
}

/**
 * High-level entry point for a RESIZE drag. In cell_center mode we keep the
 * element's center on its active cell while snapping the moving side to the
 * nearest grid line (so resize still feels gridded). In line_align mode we
 * only snap the moving side.
 */
export function snapResize(params: {
    rect: Rect
    corner: 'nw' | 'ne' | 'sw' | 'se'
    docW: number
    docH: number
    mode: SnapMode
    density: GridDensity
    thresholdDoc: number
}): ResizeSnapResult {
    const { rect, corner, docW, docH, mode, density, thresholdDoc } = params
    if (mode === 'off') {
        return { x: rect.x, y: rect.y, width: rect.width, height: rect.height, hits: [] }
    }
    return snapRectResizeLineAlign(rect, corner, docW, docH, density, thresholdDoc)
}
