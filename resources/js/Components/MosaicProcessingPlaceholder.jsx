/**
 * Grid/drawer “preview not ready” tile — branded Jackpot symbol mosaic (see `components/assets/AssetPlaceholder.tsx`).
 */
import { useMemo } from 'react'
import AssetPlaceholder, { inferAssetPlaceholderFileType } from '../components/assets/AssetPlaceholder'
import { getAssetProcessingPlaceholderCopy } from '../utils/getAssetProcessingPlaceholderCopy.js'

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
    size: _size = 'lg',
    className = '',
    videoPlayGlyph = null,
}) {
    const copy = useMemo(
        () => getAssetProcessingPlaceholderCopy(asset, visualState, placeholderHint),
        [asset, visualState, placeholderHint],
    )

    const status = useMemo(() => {
        if (visualState.kind === 'failed' || placeholderHint === 'failed') {
            return 'failed'
        }
        if (visualState.kind === 'preview_unavailable' || visualState.kind === 'model_3d_stub_raster') {
            return 'unavailable'
        }
        if (copy.animate) {
            return 'processing'
        }
        return 'unavailable'
    }, [visualState.kind, placeholderHint, copy.animate])

    const pill = useMemo(() => {
        if (!copy.badgeShort) {
            return undefined
        }
        const t = copy.badgeTone
        const tone =
            t === 'danger' ? 'danger' : t === 'warning' ? 'warning' : t === 'processing' ? 'processing' : 'neutral'
        return { short: copy.badgeShort, tone }
    }, [copy.badgeShort, copy.badgeTone])

    const fileType = useMemo(() => inferAssetPlaceholderFileType(asset), [asset])

    const extensionLabel = useMemo(() => {
        if (status !== 'unavailable') {
            return undefined
        }
        const raw = asset?.file_extension || asset?.original_filename?.split?.('.')?.pop() || ''
        const ext = String(raw)
            .replace(/^\./, '')
            .trim()
        return ext ? ext.toUpperCase() : copy.typeMark || undefined
    }, [asset, status, copy.typeMark])

    const brandHex = primaryColor || brand?.primary_color || undefined

    return (
        <AssetPlaceholder
            className={className}
            status={status}
            fileType={fileType}
            brandColor={brandHex}
            seed={asset?.id ?? asset?.original_filename ?? 'mosaic'}
            label={copy.headline}
            footerSubtext={copy.helper || undefined}
            centerSlot={copy.videoPlaySlot ? videoPlayGlyph : undefined}
            extensionLabel={extensionLabel}
            pill={pill}
        />
    )
}
