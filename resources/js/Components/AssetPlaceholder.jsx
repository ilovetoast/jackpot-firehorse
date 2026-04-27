import { PhotoIcon } from '@heroicons/react/24/outline'
import FileTypeIcon from './FileTypeIcon'

function isImageAsset(asset) {
  if (!asset) return false
  const mime = (asset.mime_type || '').toLowerCase()
  if (mime.startsWith('image/')) return true
  const ext = (asset.file_extension || '').toLowerCase().replace(/^\./, '')
  return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tif', 'tiff', 'heic', 'avif'].includes(ext)
}

/** Video grid placeholder: single frosted play (matches AssetCard poster overlay), not FileTypeIcon + label. */
function isVideoAsset(asset) {
  if (!asset) return false
  const mime = (asset.mime_type || '').toLowerCase()
  if (mime.startsWith('video/')) return true
  const ext = (asset.file_extension || asset?.original_filename?.split?.('.')?.pop() || '')
    .toLowerCase()
    .replace(/^\./, '')
  return ['mp4', 'mov', 'avi', 'webm', 'mkv', 'm4v'].includes(ext)
}

/** Match {@link AssetImagePickerField} empty state — outline photo, no circular badge */
const PHOTO_ICON_SIZE = {
  sm: 'w-8 h-8',
  md: 'w-10 h-10',
  lg: 'w-12 h-12',
}

/**
 * Muted file / video tile — same family as the image placeholder: neutral ground, no blue/sky cast.
 * (Font specimen drawer in {@link UploadedFontSpecimenPreview} still uses a slightly richer gradient.)
 */
const MUTED_PLACEHOLDER_TILE =
  'items-center justify-center gap-1 rounded-2xl bg-gradient-to-b from-slate-50/98 via-gray-50/95 to-slate-100/90 px-2 py-2 text-center ring-1 ring-slate-200/70 shadow-[inset_0_1px_0_rgba(255,255,255,0.5)]'

function extensionBadgeFromAsset(asset) {
  const raw = asset?.file_extension || asset?.original_filename?.split?.('.')?.pop() || ''
  const ext = String(raw)
    .replace(/^\./, '')
    .trim()
  return ext ? ext.toUpperCase() : 'FILE'
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

  if (isVideoAsset(asset)) {
    const playGlyph = size === 'sm' ? 'h-6 w-6' : 'h-8 w-8'
    const frostedPad = size === 'sm' ? 'p-2' : 'p-3'
    return (
      <div
        className={`relative flex h-full w-full min-h-0 items-center justify-center ${MUTED_PLACEHOLDER_TILE}`}
      >
        <div
          className={`rounded-full bg-black/40 shadow-md ring-1 ring-white/25 backdrop-blur-sm ${frostedPad}`}
        >
          <svg className={`${playGlyph} text-white`} fill="currentColor" viewBox="0 0 24 24" aria-hidden>
            <path d="M8 5v14l11-7z" />
          </svg>
        </div>
      </div>
    )
  }

  const iconSize = size === 'sm' ? 'sm' : size === 'md' ? 'md' : 'lg'

  return (
    <div className={`relative flex h-full w-full min-h-0 flex-col ${MUTED_PLACEHOLDER_TILE}`}>
      <FileTypeIcon
        fileExtension={asset?.file_extension}
        mimeType={asset?.mime_type}
        size={iconSize}
        iconClassName="text-slate-400"
      />
      <span className="max-w-full truncate text-[10px] font-medium uppercase tracking-[0.06em] text-slate-500 sm:text-[11px]">
        {extensionBadgeFromAsset(asset)}
      </span>
    </div>
  )
}
