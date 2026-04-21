import { PhotoIcon } from '@heroicons/react/24/outline'
import FileTypeIcon from './FileTypeIcon'

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

/** Light specimen tile — same language as {@link UploadedFontSpecimenPreview} drawer (soft white → cool slate/sky). */
const SPECIMEN_TILE_CLASS =
  'items-center justify-center gap-1 rounded-2xl bg-gradient-to-b from-white via-slate-50 to-sky-100/75 px-2 py-2 text-center ring-1 ring-slate-200/90 shadow-[inset_0_1px_0_rgba(255,255,255,0.85)]'

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

  const iconSize = size === 'sm' ? 'sm' : size === 'md' ? 'md' : 'lg'

  return (
    <div className={`relative flex h-full w-full min-h-0 flex-col ${SPECIMEN_TILE_CLASS}`}>
      <FileTypeIcon
        fileExtension={asset?.file_extension}
        mimeType={asset?.mime_type}
        size={iconSize}
        iconClassName="text-slate-600"
      />
      <span className="max-w-full truncate text-[10px] font-semibold uppercase tracking-[0.06em] text-slate-800 sm:text-[11px]">
        {extensionBadgeFromAsset(asset)}
      </span>
    </div>
  )
}
