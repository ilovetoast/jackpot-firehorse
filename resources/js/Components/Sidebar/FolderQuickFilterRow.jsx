import { useMemo } from 'react'
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react'
import FolderQuickFilterFlyout from './FolderQuickFilterFlyout'
import { resolveQuickFilterTone } from '../../utils/folderQuickFilterTone'

/**
 * Phase 4 — single quick-filter row inside the contextual nested list.
 *
 * Phase 4.3 — active dimensions are indicated by a leading dot and a tonal
 *             label promotion. No inline value/count.
 * Phase 4.4 — visual brand integration:
 *   - Open row uses the same brand-darkened bg as the active folder row,
 *     so it reads as "this dimension is the active one within the folder".
 *   - No focus rings or outlines — interaction is communicated through
 *     background tone only (calmer, no double-outline ever).
 *   - Tone is received from the parent so the row + flyout share one brand
 *     palette in a single derivation.
 */
export default function FolderQuickFilterRow({
    field,
    categoryId,
    categorySlug,
    /** Other folder quick-filter field keys on this folder — cleared when this dimension applies (single-slot UX). */
    exclusiveQuickFilterKeys = [],
    isActive = false,
    activeValueCount = 0,
    /** Phase 5.2 — pinned by admin; shows a subtle leading glyph. */
    isPinned = false,
    textColor,
    activeAccentColor,
    tone: incomingTone,
}) {
    const titleAttr = useMemo(() => {
        const pinSuffix = isPinned ? ' · pinned' : ''
        if (!isActive) return `${field.label}${pinSuffix}`
        if (activeValueCount === 1) {
            return `${field.label} · 1 active value${pinSuffix}`
        }
        if (activeValueCount > 1) {
            return `${field.label} · ${activeValueCount} active values${pinSuffix}`
        }
        return `${field.label} · active${pinSuffix}`
    }, [field.label, isActive, activeValueCount, isPinned])

    const tone = useMemo(
        () => incomingTone || resolveQuickFilterTone(textColor),
        [incomingTone, textColor]
    )
    const dotColor = activeAccentColor || tone.labelStrong

    return (
        <Popover className="relative">
            {({ open, close }) => {
                return (
                    <>
                        <PopoverButton
                            type="button"
                            title={titleAttr}
                            aria-label={titleAttr}
                            // Phase 4.5 — open row no longer adds its own
                            // darkened bg; the attached flyout to its right
                            // already communicates "this row is open" without
                            // stacking another dark band on top of the active
                            // folder's highlight. Interaction state
                            // (idle/hover/active) is conveyed by foreground
                            // weight + a soft hover bg only. NO ring, NO
                            // outline.
                            className="group/qf relative flex w-full items-center gap-2 rounded-md px-2 py-[3px] text-left text-[12px] leading-[1.35] transition-[color] duration-100 ease-out hover:[background-color:var(--qf-row-hover)] focus:outline-none focus-visible:outline-none"
                            style={{
                                color: open || isActive ? tone.labelStrong : tone.labelWeak,
                                fontWeight: isActive ? 500 : 400,
                                backgroundColor: 'transparent',
                                ['--qf-row-hover']: tone.rowHoverBg,
                            }}
                        >
                            {/* Active dimension indicator. Reserves a 6px
                                column so active vs inactive rows align
                                identically — no layout shift on toggle. */}
                            <span
                                aria-hidden
                                className="flex h-[14px] w-[6px] shrink-0 items-center justify-center"
                            >
                                <span
                                    className="block h-[5px] w-[5px] rounded-full transition-opacity duration-150"
                                    style={{
                                        background: dotColor,
                                        opacity: isActive ? 0.95 : 0,
                                    }}
                                />
                            </span>
                            <span className="min-w-0 flex-1 truncate">
                                {field.label}
                            </span>
                            {/* Phase 5.2 — trailing pin glyph. Sits in the
                                row's right gutter so it never displaces
                                the active-dimension dot in the left rail.
                                Subtle by design: same weak label tone, no
                                background. */}
                            {isPinned ? (
                                <PinGlyph
                                    className="h-[10px] w-[10px] shrink-0"
                                    color={tone.labelWeak}
                                />
                            ) : null}
                        </PopoverButton>
                        <PopoverPanel
                            transition
                            // No gap — panel starts at the trigger's right edge so it
                            // meets the library column edge (sidebar width is w-64 / xl:w-72;
                            // a fixed 16px gap left a visible seam on narrower widths).
                            anchor={{ to: 'right start', gap: 0, offset: 0 }}
                            className="z-[220] motion-safe:transition motion-safe:duration-[130ms] motion-safe:ease-out data-[closed]:translate-x-[-2px] data-[closed]:opacity-0 motion-reduce:transition-none motion-reduce:data-[closed]:opacity-100"
                        >
                            <FolderQuickFilterFlyout
                                field={field}
                                categoryId={categoryId}
                                categorySlug={categorySlug}
                                tone={tone}
                                exclusiveQuickFilterKeys={exclusiveQuickFilterKeys}
                                onRequestClose={close}
                            />
                        </PopoverPanel>
                    </>
                )
            }}
        </Popover>
    )
}

/**
 * Phase 5.2 — pin glyph. Tiny SVG so it never dominates the row label.
 * Visually a thumbtack outline; rendered in tone.labelWeak so it reads as
 * metadata, not an action.
 */
function PinGlyph({ className = 'h-[10px] w-[10px]', color = 'currentColor' }) {
    return (
        <svg
            viewBox="0 0 12 12"
            className={className}
            aria-hidden
            fill="none"
            stroke={color}
            strokeWidth="1.4"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M7.2 1.5l3.3 3.3-1.6 0.5-2.7 2.7 0.4 1.7-1.1 1.1-2.0-2.0-2.5 2.5" />
            <path d="M5.5 5.5l1 1" />
        </svg>
    )
}
