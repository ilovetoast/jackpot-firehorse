/**
 * AssetImagePickerFieldMulti — Multi-select asset picker with thumbnail grid.
 * Shows selected assets as thumbnails, opens AssetImagePicker modal to add/change selection.
 */
import { useState } from 'react'
import { PhotoIcon, XMarkIcon } from '@heroicons/react/24/outline'
import AssetImagePicker from './AssetImagePicker'

export default function AssetImagePickerFieldMulti({
  value = [],
  onChange,
  fetchAssets,
  title = 'Select images',
  defaultCategoryLabel = 'Photography',
  contextCategory = 'photography',
  maxSelection = 6,
  recommendedText = '',
  label = '',
  disabled = false,
  className = '',
}) {
  const [pickerOpen, setPickerOpen] = useState(false)
  const items = Array.isArray(value) ? value : []
  const canAdd = items.length < maxSelection && !disabled

  const handleSelect = (result) => {
    if (result?.asset_ids && result?.assets) {
      const next = result.assets.map((a) => ({
        asset_id: a.id,
        preview_url: a.thumbnail_url ?? a.final_thumbnail_url ?? a.preview_thumbnail_url ?? null,
        title: a.title ?? null,
      }))
      onChange?.(next)
    }
    setPickerOpen(false)
  }

  const handleRemove = (e, index) => {
    e.stopPropagation()
    if (disabled) return
    const next = items.filter((_, i) => i !== index)
    onChange?.(next)
  }

  return (
    <div className={className}>
      {label && (
        <div className="flex items-center justify-between mb-2">
          <label className="text-sm font-medium text-gray-700">{label}</label>
          <span className="text-xs text-gray-500">
            {items.length}/{maxSelection}
            {recommendedText && ` · ${recommendedText}`}
          </span>
        </div>
      )}
      <div className="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-3">
        {items.map((item, i) => (
          <div
            key={item.asset_id ?? i}
            className="relative group rounded-lg border-2 border-gray-200 overflow-hidden bg-gray-50 aspect-square"
          >
            {item.preview_url ? (
              <img
                src={item.preview_url}
                alt=""
                className="w-full h-full object-contain"
                onError={(e) => {
                  e.target.style.display = 'none'
                }}
              />
            ) : (
              <div className="w-full h-full flex items-center justify-center text-gray-400">
                <PhotoIcon className="w-8 h-8" />
              </div>
            )}
            {!disabled && (
              <button
                type="button"
                onClick={(e) => handleRemove(e, i)}
                className="absolute top-1 right-1 p-1 rounded-full bg-gray-800/70 text-white hover:bg-red-600 transition-colors opacity-0 group-hover:opacity-100"
                aria-label="Remove"
              >
                <XMarkIcon className="w-3.5 h-3.5" />
              </button>
            )}
          </div>
        ))}
        {canAdd && (
          <button
            type="button"
            onClick={() => setPickerOpen(true)}
            className="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-300 bg-gray-50/50 hover:border-indigo-300 hover:bg-indigo-50/30 aspect-square transition-colors"
          >
            <PhotoIcon className="w-8 h-8 text-gray-400" />
            <span className="mt-1 text-xs font-medium text-gray-500">Add</span>
          </button>
        )}
      </div>

      <AssetImagePicker
        open={pickerOpen}
        onClose={() => setPickerOpen(false)}
        fetchAssets={fetchAssets}
        onSelect={handleSelect}
        title={title}
        defaultCategoryLabel={defaultCategoryLabel}
        contextCategory={contextCategory}
        singleSelect={false}
        maxSelection={maxSelection}
        initialSelectedIds={items.map((i) => i.asset_id).filter(Boolean)}
        disabled={disabled}
      />
    </div>
  )
}
