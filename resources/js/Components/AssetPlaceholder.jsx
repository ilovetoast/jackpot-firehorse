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

export default function AssetPlaceholder({ asset, primaryColor, size = 'lg' }) {
  const photoClass = PHOTO_ICON_SIZE[size] || PHOTO_ICON_SIZE.lg

  if (isImageAsset(asset)) {
    return (
      <div className="relative flex items-center justify-center w-full h-full rounded-lg overflow-hidden">
        <PhotoIcon className={`${photoClass} text-slate-400`} aria-hidden />
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
