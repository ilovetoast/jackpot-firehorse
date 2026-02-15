/**
 * AssetImagePickerField — Trigger + preview wrapper for AssetImagePicker.
 * Shows preview on main page, opens modal on click.
 * Handles both asset_id (preview from thumbnail) and file (preview from blob).
 */
import { useState } from 'react'
import { PhotoIcon } from '@heroicons/react/24/outline'
import AssetImagePicker from './AssetImagePicker'

export default function AssetImagePickerField({
  value = null, // { asset_id?, file?, preview_url? } or legacy: just preview_url string
  onChange,
  fetchAssets,
  title = 'Select image',
  defaultCategoryLabel = 'Logos',
  contextCategory = null, // 'logos' | 'icons' | 'photography' | null — enables contextual tab
  aspectRatio = null,
  minWidth = 100,
  minHeight = 100,
  placeholder = 'Click or drag to upload',
  helperText = '',
  disabled = false,
  className = '',
  getAssetDownloadUrl = null,
}) {
  const [pickerOpen, setPickerOpen] = useState(false)

  // Normalize value: can be { asset_id, file, preview_url } or legacy string (path/url)
  const previewUrl =
    typeof value === 'string'
      ? value
      : value?.preview_url ?? null
  const assetId = value?.asset_id ?? null
  const hasValue = !!(previewUrl || assetId || value?.file)

  const handleSelect = (result) => {
    if (result.asset_id) {
      // Prefer preview_url (blob) for immediate display; thumbnail_url may 404 until thumbnails are generated
      onChange?.({ asset_id: result.asset_id, thumbnail_url: result.thumbnail_url ?? null, preview_url: result.preview_url ?? result.thumbnail_url ?? null })
    } else if (result.file) {
      onChange?.({ file: result.file, preview_url: result.preview_url })
    }
    setPickerOpen(false)
  }

  const handleRemove = (e) => {
    e.stopPropagation()
    if (disabled) return
    onChange?.(null)
  }

  return (
    <div className={className}>
      <div
        onClick={() => !disabled && setPickerOpen(true)}
        className={`relative flex flex-col items-center justify-center rounded-xl border-2 border-dashed transition-all cursor-pointer min-h-[140px] ${
          hasValue
            ? 'border-gray-200/80 bg-gray-50/30 hover:border-gray-300 hover:bg-gray-50/50'
            : 'border-gray-300/80 bg-gray-50/40 hover:border-indigo-300/60 hover:bg-indigo-50/20'
        } ${disabled ? 'opacity-60 cursor-not-allowed' : ''}`}
      >
        {hasValue ? (
          <div className="w-full p-4">
            <div className="flex items-center justify-between mb-2">
              <p className="text-xs font-medium text-gray-700">Current image</p>
              <div className="flex items-center gap-3">
                <span className="text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                  Change
                </span>
                <button
                  type="button"
                  onClick={handleRemove}
                  className="text-xs text-gray-500 hover:text-red-600 font-medium"
                >
                  Remove
                </button>
              </div>
            </div>
            <img
              src={previewUrl}
              alt=""
              className="h-24 w-auto max-w-full border border-gray-200 rounded-lg bg-gray-300 p-3 mx-auto object-contain"
              onError={(e) => {
                e.target.style.display = 'none'
              }}
            />
            <p className="mt-2 text-xs text-gray-500 text-center">Click to replace</p>
          </div>
        ) : (
          <>
            <PhotoIcon className="w-12 h-12 text-gray-400 mx-auto" />
            <p className="mt-3 text-sm font-medium text-gray-600">{placeholder}</p>
            {helperText && <p className="mt-1 text-xs text-gray-500">{helperText}</p>}
          </>
        )}
      </div>
      {helperText && !hasValue && <p className="mt-2 text-xs text-gray-500">{helperText}</p>}

      <AssetImagePicker
        open={pickerOpen}
        onClose={() => setPickerOpen(false)}
        fetchAssets={fetchAssets}
        onSelect={handleSelect}
        title={title}
        defaultCategoryLabel={defaultCategoryLabel}
        contextCategory={contextCategory}
        aspectRatio={aspectRatio}
        minWidth={minWidth}
        minHeight={minHeight}
        disabled={disabled}
        getAssetDownloadUrl={getAssetDownloadUrl}
      />
    </div>
  )
}
