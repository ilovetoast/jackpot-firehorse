/**
 * D10.1 — Mini Media Manager for download landing background selection.
 * Grid-only, thumbnails, hover shows resolution + quality rating, disabled for assets <1920×1080.
 * Multi-select max maxSelection, Confirm returns selected IDs. No uploads, no editing, no cross-brand.
 */
import { useState, useEffect, useCallback } from 'react'
import { XMarkIcon, CheckIcon } from '@heroicons/react/24/outline'

const MIN_WIDTH = 1920
const MIN_HEIGHT = 1080

export default function MiniAssetPicker({
  open,
  onClose,
  fetchAssets,
  maxSelection = 5,
  initialSelectedIds = [],
  disabledAssetReason = (asset) => {
    if (asset.width != null && asset.height != null && (asset.width < MIN_WIDTH || asset.height < MIN_HEIGHT)) {
      return 'Must be at least 1920×1080'
    }
    return null
  },
  onConfirm,
  title = 'Select background images',
}) {
  const [assets, setAssets] = useState([])
  const [loading, setLoading] = useState(false)
  const [selectedIds, setSelectedIds] = useState([])
  const [confirming, setConfirming] = useState(false)

  const loadAssets = useCallback(() => {
    if (!open || !fetchAssets) return
    setLoading(true)
    setAssets([])
    fetchAssets()
      .then((res) => setAssets(res?.assets ?? res ?? []))
      .catch(() => setAssets([]))
      .finally(() => setLoading(false))
  }, [open, fetchAssets])

  useEffect(() => {
    if (open) {
      loadAssets()
      setSelectedIds(Array.isArray(initialSelectedIds) ? initialSelectedIds.slice(0, maxSelection) : [])
    }
  }, [open, loadAssets, initialSelectedIds, maxSelection])

  const toggle = (asset) => {
    const reason = disabledAssetReason(asset)
    if (reason) return
    const id = asset.id
    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : prev.length >= maxSelection ? prev : [...prev, id]
    )
  }

  const handleConfirm = () => {
    setConfirming(true)
    onConfirm(selectedIds)
    onClose()
    setConfirming(false)
  }

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
      <div className="flex min-h-full items-center justify-center p-4">
        <div className="fixed inset-0 bg-black/50" onClick={onClose} aria-hidden />
        <div className="relative bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[85vh] overflow-hidden flex flex-col">
          <div className="px-4 py-3 border-b border-gray-200 flex items-center justify-between flex-shrink-0">
            <h3 className="text-sm font-semibold text-gray-900">{title}</h3>
            <div className="flex items-center gap-3">
              <span className="text-xs text-gray-500">
                {selectedIds.length} / {maxSelection} selected
              </span>
              <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-600 p-1">
                <XMarkIcon className="w-5 h-5" />
              </button>
            </div>
          </div>
          <div className="p-4 overflow-y-auto flex-1 min-h-0">
            {loading ? (
              <p className="text-sm text-gray-500">Loading…</p>
            ) : (
              <div className="grid grid-cols-4 sm:grid-cols-5 md:grid-cols-6 gap-3">
                {assets.map((asset) => {
                  const disabledReason = disabledAssetReason(asset)
                  const isDisabled = !!disabledReason
                  const isSelected = selectedIds.includes(asset.id)
                  const resolution =
                    asset.width != null && asset.height != null
                      ? `${asset.width}×${asset.height}`
                      : null
                  return (
                    <button
                      key={asset.id}
                      type="button"
                      onClick={() => toggle(asset)}
                      disabled={isDisabled}
                      title={isDisabled ? disabledReason : (resolution || asset.original_filename || '')}
                      className={`relative rounded-lg border-2 overflow-hidden transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 ${
                        isDisabled
                          ? 'opacity-50 cursor-not-allowed border-gray-200'
                          : isSelected
                            ? 'border-indigo-600 ring-2 ring-indigo-600 ring-offset-1'
                            : 'border-gray-200 hover:border-gray-300'
                      }`}
                    >
                      <div className="aspect-square bg-gray-100">
                        {asset.thumbnail_url ? (
                          <img
                            src={asset.thumbnail_url}
                            alt=""
                            className="w-full h-full object-cover"
                          />
                        ) : (
                          <div className="w-full h-full flex items-center justify-center text-xs text-gray-400">
                            —
                          </div>
                        )}
                      </div>
                      {isSelected && (
                        <div className="absolute inset-0 flex items-center justify-center bg-indigo-600/20 pointer-events-none">
                          <div className="rounded-full bg-indigo-600 p-1">
                            <CheckIcon className="w-4 h-4 text-white" />
                          </div>
                        </div>
                      )}
                      {/* Hover: resolution + quality */}
                      {!isDisabled && (resolution || asset.quality_rating != null) && (
                        <div className="absolute bottom-0 left-0 right-0 bg-black/70 text-white text-[10px] px-1 py-0.5 truncate">
                          {resolution || ''}
                          {asset.quality_rating != null && resolution ? ' · ' : ''}
                          {asset.quality_rating != null ? `★ ${asset.quality_rating}` : ''}
                        </div>
                      )}
                    </button>
                  )
                })}
              </div>
            )}
          </div>
          <div className="px-4 py-3 border-t border-gray-200 flex justify-end gap-2 flex-shrink-0">
            <button
              type="button"
              onClick={onClose}
              className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleConfirm}
              disabled={selectedIds.length === 0 || confirming}
              className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 disabled:pointer-events-none"
            >
              Confirm
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
