import { useMemo } from 'react'
import { PhotoIcon } from '@heroicons/react/24/outline'
import FileTypeIcon from './FileTypeIcon'
import AssetProcessingPlaceholder from './AssetProcessingPlaceholder'
import { getAssetCardVisualState } from '../utils/assetCardVisualState.js'
import { supportsThumbnail } from '../utils/thumbnailUtils.js'
import { getAssetPlaceholderTheme } from '../utils/getAssetPlaceholderTheme.js'

function isImageAsset(asset) {
    if (!asset) return false
    const mime = (asset.mime_type || '').toLowerCase()
    if (mime.startsWith('image/')) return true
    const ext = (asset.file_extension || '').toLowerCase().replace(/^\./, '')
    return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tif', 'tiff', 'heic', 'avif'].includes(ext)
}

function isVideoAsset(asset) {
    if (!asset) return false
    const mime = (asset.mime_type || '').toLowerCase()
    if (mime.startsWith('video/')) return true
    const ext = (asset.file_extension || asset?.original_filename?.split?.('.')?.pop() || '')
        .toLowerCase()
        .replace(/^\./, '')
    return ['mp4', 'mov', 'avi', 'webm', 'mkv', 'm4v'].includes(ext)
}

function extLower(asset) {
    return (asset?.file_extension || asset?.original_filename?.split?.('.')?.pop() || '')
        .toLowerCase()
        .replace(/^\./, '')
}

const PHOTO_ICON_SIZE = {
    sm: 'w-8 h-8',
    md: 'w-10 h-10',
    lg: 'w-12 h-12',
}

function extensionBadgeFromAsset(asset) {
    const raw = asset?.file_extension || asset?.original_filename?.split?.('.')?.pop() || ''
    const ext = String(raw)
        .replace(/^\./, '')
        .trim()
    return ext ? ext.toUpperCase() : 'FILE'
}

/**
 * @param {'processing'|'failed'|'unavailable'|'skipped'|'default'|null|undefined} [placeholderHint]
 * @param {string|null} [ephemeralLocalPreviewUrl]
 */
export default function AssetPlaceholder({
    asset,
    primaryColor = null,
    brand = null,
    size = 'lg',
    rich = false,
    placeholderHint = null,
    ephemeralLocalPreviewUrl = null,
}) {
    const photoClass = PHOTO_ICON_SIZE[size] || PHOTO_ICON_SIZE.lg
    const iconSize = size === 'sm' ? 'sm' : size === 'md' ? 'md' : 'lg'
    const brandHex = primaryColor || '#6366f1'

    const brandTheme = useMemo(
        () => ({
            primary_color: brandHex,
            accent_color: brand?.accent_color ?? brand?.secondary_color,
        }),
        [brandHex, brand?.accent_color, brand?.secondary_color],
    )

    const surfaceStyle = useMemo(() => getAssetPlaceholderTheme(asset, brandTheme).surfaceStyle, [asset, brandTheme])

    const richImageVisualState = useMemo(() => {
        if (!rich || !isImageAsset(asset)) return null
        return getAssetCardVisualState(asset, { ephemeralLocalPreviewUrl })
    }, [rich, asset, ephemeralLocalPreviewUrl])

    const richVideoVisualState = useMemo(() => {
        if (!rich || !isVideoAsset(asset)) return null
        const v = getAssetCardVisualState(asset, { ephemeralLocalPreviewUrl })
        if (v.kind === 'ready' || v.kind === 'local_preview') return null
        return v
    }, [rich, asset, ephemeralLocalPreviewUrl])

    const nonImageRichCard = useMemo(() => {
        if (!rich || !asset) return null
        if (isImageAsset(asset) || isVideoAsset(asset)) return null
        const e = extLower(asset)
        if (!supportsThumbnail(asset.mime_type, e) && !asset.pending_finalize_client_tile) return null
        const v = getAssetCardVisualState(asset, { ephemeralLocalPreviewUrl })
        if (v.kind === 'ready' || v.kind === 'local_preview') return null
        return v
    }, [rich, asset, ephemeralLocalPreviewUrl])

    if (nonImageRichCard) {
        return (
            <AssetProcessingPlaceholder
                asset={asset}
                primaryColor={brandHex}
                brand={brand}
                visualState={nonImageRichCard}
                placeholderHint={placeholderHint || 'default'}
                size={size}
            />
        )
    }

    if (richVideoVisualState) {
        const playGlyph = size === 'sm' ? 'h-7 w-7' : 'h-9 w-9'
        const frostedPad = size === 'sm' ? 'p-2' : 'p-2.5'
        const play = (
            <div
                className={`rounded-full bg-black/45 shadow-lg ring-1 ring-white/20 backdrop-blur-sm ${frostedPad}`}
            >
                <svg className={`${playGlyph} text-white`} fill="currentColor" viewBox="0 0 24 24" aria-hidden>
                    <path d="M8 5v14l11-7z" />
                </svg>
            </div>
        )
        return (
            <AssetProcessingPlaceholder
                asset={asset}
                primaryColor={brandHex}
                brand={brand}
                visualState={richVideoVisualState}
                placeholderHint={placeholderHint || 'default'}
                size={size}
                videoPlayGlyph={play}
            />
        )
    }

    if (richImageVisualState) {
        return (
            <AssetProcessingPlaceholder
                asset={asset}
                primaryColor={brandHex}
                brand={brand}
                visualState={richImageVisualState}
                placeholderHint={placeholderHint || 'default'}
                size={size}
            />
        )
    }

    if (isImageAsset(asset) && !rich) {
        return (
            <div
                className="relative flex h-full w-full items-center justify-center overflow-hidden rounded-lg"
                style={surfaceStyle}
            >
                <PhotoIcon className={`${photoClass} text-white/75`} aria-hidden />
            </div>
        )
    }

    if (isVideoAsset(asset)) {
        const playGlyph = size === 'sm' ? 'h-6 w-6' : 'h-8 w-8'
        const frostedPad = size === 'sm' ? 'p-2' : 'p-3'
        return (
            <div
                className="relative flex h-full w-full min-h-0 items-center justify-center overflow-hidden rounded-2xl jp-asset-processing-placeholder--animated jp-asset-placeholder-soft-pulse"
                style={surfaceStyle}
            >
                <div className="jp-asset-placeholder-shimmer-track rounded-[inherit]">
                    <div
                        className="jp-asset-placeholder-shimmer-bar"
                        style={{
                            background: `linear-gradient(118deg, transparent 0%, transparent 38%, var(--asset-placeholder-sheen, rgba(255,255,255,0.16)) 50%, transparent 62%, transparent 100%)`,
                        }}
                    />
                </div>
                <div
                    className={`relative z-[1] rounded-full bg-black/40 shadow-md ring-1 ring-white/25 backdrop-blur-sm ${frostedPad}`}
                >
                    <svg className={`${playGlyph} text-white`} fill="currentColor" viewBox="0 0 24 24" aria-hidden>
                        <path d="M8 5v14l11-7z" />
                    </svg>
                </div>
            </div>
        )
    }

    return (
        <div
            className="relative flex h-full w-full min-h-0 flex-col items-center justify-center gap-1 overflow-hidden rounded-2xl px-2 py-2 ring-1 ring-white/12"
            style={surfaceStyle}
        >
            <FileTypeIcon
                fileExtension={asset?.file_extension}
                mimeType={asset?.mime_type}
                size={iconSize}
                iconClassName="text-white/55"
            />
            <span className="max-w-full truncate font-mono text-[10px] font-semibold uppercase tracking-[0.08em] text-white/70 sm:text-[11px]">
                {extensionBadgeFromAsset(asset)}
            </span>
        </div>
    )
}
