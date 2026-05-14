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
    isActive = false,
    activeValueCount = 0,
    textColor,
    activeAccentColor,
    tone: incomingTone,
}) {
    const titleAttr = useMemo(() => {
        if (!isActive) return field.label
        if (activeValueCount === 1) return `${field.label} · 1 active value`
        if (activeValueCount > 1) {
            return `${field.label} · ${activeValueCount} active values`
        }
        return `${field.label} · active`
    }, [field.label, isActive, activeValueCount])

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
                        </PopoverButton>
                        <PopoverPanel
                            transition
                            // Phase 5.1 — pin the panel flush with the
                            // sidebar's outer edge. The 288px sidebar +
                            // (row right padding 8 + ul mr-1 4 + section
                            // pr-3 12) → row right edge at ~272px → gap 16
                            // lands the panel's left edge at exactly 288px.
                            // Reverted from Phase 5's overlap experiment;
                            // the flush boundary scans cleaner against the
                            // square-cornered surface below.
                            anchor={{ to: 'right start', gap: 16, offset: 0 }}
                            className="z-[220] motion-safe:transition motion-safe:duration-[130ms] motion-safe:ease-out data-[closed]:translate-x-[-2px] data-[closed]:opacity-0 motion-reduce:transition-none motion-reduce:data-[closed]:opacity-100"
                        >
                            <FolderQuickFilterFlyout
                                field={field}
                                categoryId={categoryId}
                                tone={tone}
                                onRequestClose={close}
                            />
                        </PopoverPanel>
                    </>
                )
            }}
        </Popover>
    )
}
