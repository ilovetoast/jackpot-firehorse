import { PhotoIcon } from '@heroicons/react/24/outline'
import FileTypeIcon from './FileTypeIcon'
import { buildBrandCinematicTileBackground, hexToRgba } from '../utils/colorUtils'

function isImageAsset(asset) {
  if (!asset) return false
  const mime = (asset.mime_type || '').toLowerCase()
  if (mime.startsWith('image/')) return true
  const ext = (asset.file_extension || '').toLowerCase().replace(/^\./, '')
  return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tif', 'tiff', 'heic', 'avif'].includes(ext)
}

/** Match {@link AssetImagePickerField} empty state — outline photo, no circular badge */
const PHOTO_ICON_SIZE = {
  sm: 'w-8 h-8',
  md: 'w-10 h-10',
  lg: 'w-12 h-12',
}

/**
 * Gradient + icon accent for “preview not available” (skipped / failed) on supported types — not a generic gray tile.
 */
function richPlaceholderClasses(asset) {
  const ext = (asset?.file_extension || asset?.original_filename?.split?.('.')?.pop() || '')
    .toLowerCase()
    .replace(/^\./, '')
  const mime = (asset?.mime_type || '').toLowerCase()

  if (['psd', 'psb'].includes(ext) || mime.includes('photoshop')) {
    return {
      wrap: 'bg-gradient-to-br from-sky-300/35 via-blue-500/25 to-indigo-950/50',
      ring: 'ring-1 ring-indigo-400/30',
      icon: 'text-indigo-950 drop-shadow-sm',
    }
  }
  if (['ai', 'eps'].includes(ext) || mime.includes('illustrator')) {
    return {
      wrap: 'bg-gradient-to-br from-orange-300/40 via-amber-500/25 to-orange-950/45',
      ring: 'ring-1 ring-orange-400/35',
      icon: 'text-orange-950 drop-shadow-sm',
    }
  }
  if (['mp4', 'mov', 'avi', 'webm', 'mkv', 'm4v'].includes(ext) || mime.startsWith('video/')) {
    return {
      wrap: 'bg-gradient-to-br from-violet-300/35 via-fuchsia-500/20 to-purple-950/50',
      ring: 'ring-1 ring-violet-400/30',
      icon: 'text-purple-950 drop-shadow-sm',
    }
  }
  if (['tiff', 'tif', 'cr2', 'nef', 'arw', 'dng'].includes(ext) || mime.includes('tiff') || mime.includes('raw')) {
    return {
      wrap: 'bg-gradient-to-br from-emerald-300/35 via-teal-500/25 to-emerald-950/45',
      ring: 'ring-1 ring-teal-400/30',
      icon: 'text-emerald-950 drop-shadow-sm',
    }
  }
  if (mime.startsWith('image/') || ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'avif', 'bmp'].includes(ext)) {
    return {
      wrap: 'bg-gradient-to-br from-rose-200/50 via-pink-300/30 to-slate-700/40',
      ring: 'ring-1 ring-rose-300/40',
      icon: 'text-rose-950/90 drop-shadow-sm',
    }
  }
  return {
    wrap: 'bg-gradient-to-br from-slate-200/60 via-slate-300/35 to-slate-600/45',
    ring: 'ring-1 ring-slate-400/35',
    icon: 'text-slate-800 drop-shadow-sm',
  }
}

export default function AssetPlaceholder({ asset, primaryColor = null, brand = null, size = 'lg', rich = false }) {
  const photoClass = PHOTO_ICON_SIZE[size] || PHOTO_ICON_SIZE.lg

  if (isImageAsset(asset) && !rich) {
    return (
      <div className="relative flex items-center justify-center w-full h-full rounded-lg overflow-hidden">
        <PhotoIcon className={`${photoClass} text-slate-400`} aria-hidden />
      </div>
    )
  }

  if (rich) {
    const primary = brand?.primary_color || '#6366f1'
    const secondary = brand?.secondary_color || '#8b5cf6'
    const useBrandTile = Boolean(brand?.primary_color)
    const cinematicBg = buildBrandCinematicTileBackground(primary, secondary)

    const iconChipClass =
      'flex items-center justify-center w-[4.25rem] h-[4.25rem] rounded-2xl bg-white/12 backdrop-blur-md shadow-lg ring-1 ring-white/20'

    if (useBrandTile) {
      return (
        <div
          className="relative flex items-center justify-center w-full h-full rounded-2xl overflow-hidden ring-1 ring-inset ring-white/10"
          style={{
            background: cinematicBg,
            boxShadow: `inset 0 0 1px ${hexToRgba(primary, 0.35)}`,
          }}
        >
          <div className={`relative z-[1] ${iconChipClass}`}>
            <FileTypeIcon
              fileExtension={asset?.file_extension}
              mimeType={asset?.mime_type}
              size={size === 'sm' ? 'md' : 'lg'}
              iconClassName="text-white/90 drop-shadow-md"
            />
          </div>
        </div>
      )
    }

    const { wrap } = richPlaceholderClasses(asset)
    return (
      <div className="relative flex items-center justify-center w-full h-full rounded-2xl overflow-hidden ring-1 ring-inset ring-white/10">
        <div
          className="absolute inset-0"
          style={{ background: cinematicBg }}
          aria-hidden
        />
        <div className={`absolute inset-0 opacity-[0.5] ${wrap}`} aria-hidden />
        <div className={`relative z-[1] ${iconChipClass}`}>
          <FileTypeIcon
            fileExtension={asset?.file_extension}
            mimeType={asset?.mime_type}
            size={size === 'sm' ? 'md' : 'lg'}
            iconClassName="text-white/90 drop-shadow-md"
          />
        </div>
      </div>
    )
  }

  // Processing / pending (no rich failure copy): brand-tinted tile — matches cinematic rich placeholders, avoids flat white video tiles.
  const brandPrimary = primaryColor || brand?.primary_color
  if (brandPrimary) {
    const secondary = brand?.secondary_color || brand?.accent_color || brandPrimary
    const cinematicBg = buildBrandCinematicTileBackground(brandPrimary, secondary)
    const iconChipClass =
      'flex items-center justify-center w-[4.25rem] h-[4.25rem] rounded-2xl bg-white/12 backdrop-blur-md shadow-lg ring-1 ring-white/20'
    return (
      <div
        className="relative flex items-center justify-center w-full h-full rounded-2xl overflow-hidden ring-1 ring-inset ring-white/10"
        style={{
          background: cinematicBg,
          boxShadow: `inset 0 0 1px ${hexToRgba(brandPrimary, 0.35)}`,
        }}
      >
        <div className={`relative z-[1] ${iconChipClass}`}>
          <FileTypeIcon
            fileExtension={asset?.file_extension}
            mimeType={asset?.mime_type}
            size={size === 'sm' ? 'md' : 'lg'}
            iconClassName="text-white/90 drop-shadow-md"
          />
        </div>
      </div>
    )
  }

  return (
    <div className="relative flex items-center justify-center w-full h-full rounded-lg overflow-hidden">
      <div className="flex items-center justify-center w-16 h-16 rounded-full bg-white/70 backdrop-blur-sm shadow-sm">
        <FileTypeIcon
          fileExtension={asset?.file_extension}
          mimeType={asset?.mime_type}
          size={size}
          className="text-gray-500"
        />
      </div>
    </div>
  )
}
