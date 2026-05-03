/**
 * Branded deterministic pixel-mosaic surface for “preview not ready” grid/drawer tiles.
 * @see getAssetMosaicPlaceholder
 */
import { useMemo } from 'react'
import { getAssetMosaicPlaceholder } from '../utils/getAssetMosaicPlaceholder.js'
import { getAssetProcessingPlaceholderCopy } from '../utils/getAssetProcessingPlaceholderCopy.js'

function ringClassForTone(tone) {
    if (tone === 'danger') return 'ring-1 ring-red-400/35'
    if (tone === 'warning') return 'ring-1 ring-amber-300/35'
    return 'ring-1 ring-white/12'
}

function badgePillClass(tone) {
    if (tone === 'danger') return 'bg-red-600/95 text-white'
    if (tone === 'warning') return 'bg-amber-400/95 text-amber-950'
    if (tone === 'processing') return 'bg-white/14 text-white/95 backdrop-blur-[2px]'
    return 'bg-white/12 text-white/90 backdrop-blur-[2px]'
}

const HEADLINE = {
    sm: 'text-[9px] font-semibold leading-snug',
    md: 'text-[10px] font-semibold leading-snug',
    lg: 'text-[11px] font-semibold leading-snug sm:text-[12px]',
}

const HELPER = {
    sm: 'text-[8px] font-medium leading-snug text-white/58',
    md: 'text-[9px] font-medium leading-snug text-white/60',
    lg: 'text-[9px] font-medium leading-snug text-white/62 sm:text-[10px]',
}

const TYPE_MARK = {
    sm: 'text-[10px] font-mono font-semibold uppercase tracking-wider text-white/25',
    md: 'text-[11px] font-mono font-semibold uppercase tracking-wider text-white/28',
    lg: 'text-[12px] font-mono font-semibold uppercase tracking-wider text-white/30',
}

/**
 * @param {object} props
 * @param {object|null} props.asset
 * @param {string|null} [props.primaryColor]
 * @param {{ primary_color?: string, accent_color?: string }|null} [props.brand]
 * @param {object} props.visualState — from getAssetCardVisualState
 * @param {'processing'|'failed'|'unavailable'|'skipped'|'default'|null|undefined} [props.placeholderHint]
 * @param {'sm'|'md'|'lg'} [props.size='lg']
 * @param {string} [props.className]
 * @param {import('react').ReactNode} [props.videoPlayGlyph]
 */
export default function MosaicProcessingPlaceholder({
    asset,
    primaryColor = null,
    brand = null,
    visualState,
    placeholderHint = null,
    size = 'lg',
    className = '',
    videoPlayGlyph = null,
}) {
    const brandTheme = useMemo(
        () => ({
            primary_color: primaryColor || brand?.primary_color,
            accent_color: brand?.accent_color ?? brand?.secondary_color,
        }),
        [primaryColor, brand?.primary_color, brand?.accent_color, brand?.secondary_color],
    )

    const mosaic = useMemo(() => getAssetMosaicPlaceholder(asset, brandTheme, { cols: 10, rows: 6 }), [asset, brandTheme])

    const copy = useMemo(
        () => getAssetProcessingPlaceholderCopy(asset, visualState, placeholderHint),
        [asset, visualState, placeholderHint],
    )

    const ring = ringClassForTone(
        copy.badgeTone === 'danger' ? 'danger' : copy.badgeTone === 'warning' ? 'warning' : 'neutral',
    )

    const title = `${copy.headline}. ${copy.helper}`.trim()
    const sz = size === 'sm' ? 'sm' : size === 'md' ? 'md' : 'lg'
    const wm = copy.typeMark && copy.showFaintTypeWatermark

    const baseWash = `linear-gradient(165deg, hsla(${mosaic.baseHue}, 38%, 14%, 1) 0%, hsla(${mosaic.baseHue}, 28%, 9%, 1) 100%)`

    return (
        <div
            className={`jp-mosaic-processing-root jp-asset-processing-placeholder relative flex h-full w-full min-h-0 flex-col items-center justify-center gap-1 overflow-hidden rounded-2xl px-2 py-2 text-center shadow-[inset_0_1px_0_rgba(255,255,255,0.06)] ${ring} ${className}`}
            style={{
                color: 'var(--asset-placeholder-text, hsla(220, 22%, 96%, 0.94))',
                background: baseWash,
            }}
            title={title}
            role="img"
            aria-label={title}
        >
            <div
                className="pointer-events-none absolute inset-0 z-0 grid"
                style={{
                    gridTemplateColumns: `repeat(${mosaic.cols}, minmax(0, 1fr))`,
                    gridTemplateRows: `repeat(${mosaic.rows}, minmax(0, 1fr))`,
                }}
                aria-hidden
            >
                {mosaic.cells.map((cell) => (
                    <div
                        key={cell.index}
                        className={`jp-mosaic-cell min-h-0 min-w-0 ${copy.animate ? '' : 'jp-mosaic-cell--static'}`}
                        style={{
                            backgroundColor: `hsl(${cell.h} ${cell.s}% ${cell.l}%)`,
                            ['--jp-mosaic-delay']: `${cell.delayMs}ms`,
                        }}
                    />
                ))}
            </div>

            <div
                className="pointer-events-none absolute inset-0 z-[1] rounded-[inherit]"
                style={{
                    background:
                        'radial-gradient(85% 75% at 50% 45%, transparent 0%, hsla(0, 0%, 0%, 0.08) 55%, hsla(0, 0%, 0%, 0.38) 100%)',
                }}
                aria-hidden
            />

            {copy.badgeShort ? (
                <span
                    className={`pointer-events-none absolute right-2 top-2 z-[3] max-w-[calc(100%-0.75rem)] truncate rounded-md px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide shadow-sm ring-1 ring-black/20 ${badgePillClass(copy.badgeTone)}`}
                    title={copy.badgeTitle || copy.badgeShort}
                >
                    {copy.badgeShort}
                </span>
            ) : null}

            {wm ? (
                <span
                    className={`pointer-events-none absolute left-1/2 top-1/2 z-[1] -translate-x-1/2 -translate-y-1/2 select-none ${TYPE_MARK[sz]}`}
                    aria-hidden
                >
                    {copy.typeMark}
                </span>
            ) : null}

            <div className="relative z-[2] flex min-h-0 w-full flex-1 flex-col items-center justify-center gap-0.5 px-0.5 drop-shadow-[0_1px_8px_rgba(0,0,0,0.35)]">
                {copy.videoPlaySlot && videoPlayGlyph ? (
                    <>
                        {videoPlayGlyph}
                        <span className={`mt-1 max-w-full text-center ${HEADLINE[sz]} text-white/92`}>{copy.headline}</span>
                        <span className={`max-w-full text-center ${HELPER[sz]}`}>{copy.helper}</span>
                    </>
                ) : (
                    <>
                        <span className={`max-w-full text-center ${HEADLINE[sz]} text-white/[0.92]`}>{copy.headline}</span>
                        {copy.helper ? (
                            <span className={`mt-0.5 max-w-full text-center ${HELPER[sz]}`}>{copy.helper}</span>
                        ) : null}
                    </>
                )}
            </div>
        </div>
    )
}
