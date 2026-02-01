/**
 * D10 — Reusable brand-level download landing branding selector.
 * D10.1: Background selection uses MiniAssetPicker (Photography/Graphics, ≥1920×1080).
 */
import { useState } from 'react'
import { XMarkIcon } from '@heroicons/react/24/outline'
import MiniAssetPicker from '../media/MiniAssetPicker'

const COLOR_ROLES = [
  { role: 'primary', label: 'Primary' },
  { role: 'secondary', label: 'Secondary' },
  { role: 'accent', label: 'Accent' },
]

export default function DownloadBrandingSelector({
  logoAssets = [],
  selectedLogoAssetId = null,
  onLogoChange,
  primaryColor = '#6366f1',
  secondaryColor = '#64748b',
  accentColor = '#6366f1',
  selectedColorRole = 'primary',
  onColorRoleChange,
  backgroundAssets = [],
  backgroundAssetIds = [],
  onRemoveBackground,
  onBackgroundsConfirm,
  fetchBackgroundCandidates,
  maxBackgrounds = 5,
  defaultHeadline = '',
  defaultSubtext = '',
  onHeadlineChange,
  onSubtextChange,
  disabled = false,
}) {
  const [showMiniPicker, setShowMiniPicker] = useState(false)

  const palette = [
    { role: 'primary', label: 'Primary', hex: primaryColor || '#6366f1' },
    { role: 'secondary', label: 'Secondary', hex: secondaryColor || '#64748b' },
    { role: 'accent', label: 'Accent', hex: accentColor || '#6366f1' },
  ]

  const disabledAssetReason = (asset) => {
    if (asset.width != null && asset.height != null && (asset.width < 1920 || asset.height < 1080)) {
      return 'Must be at least 1920×1080'
    }
    return null
  }

  return (
    <div className="space-y-6">
      {/* Enable toggle is handled by parent */}

      {/* Logo selector — tile grid */}
      <div>
        <label className="block text-sm font-medium leading-6 text-gray-900 mb-2">Logo</label>
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            onClick={() => !disabled && onLogoChange?.(null)}
            className={`w-16 h-16 rounded-lg border-2 flex items-center justify-center text-xs font-medium transition-colors ${
              selectedLogoAssetId === null
                ? 'border-indigo-600 ring-2 ring-indigo-600 ring-offset-1 bg-indigo-50 text-indigo-700'
                : 'border-gray-200 bg-gray-50 text-gray-500 hover:border-gray-300'
            }`}
            disabled={disabled}
            title="No logo"
          >
            None
          </button>
          {(logoAssets || []).map((a) => (
            <button
              key={a.id}
              type="button"
              onClick={() => !disabled && onLogoChange?.(a.id)}
              className={`w-16 h-16 rounded-lg border-2 overflow-hidden flex-shrink-0 transition-colors ${
                selectedLogoAssetId === a.id
                  ? 'border-indigo-600 ring-2 ring-indigo-600 ring-offset-1'
                  : 'border-gray-200 hover:border-gray-300'
              }`}
              disabled={disabled}
              title={a.original_filename || 'Logo'}
            >
              {a.thumbnail_url ? (
                <img src={a.thumbnail_url} alt="" className="w-full h-full object-contain bg-white" />
              ) : (
                <span className="text-xs text-gray-400 p-1">Logo</span>
              )}
            </button>
          ))}
        </div>
      </div>

      {/* Color selector — palette tiles */}
      <div>
        <label className="block text-sm font-medium leading-6 text-gray-900 mb-2">Accent color</label>
        <div className="flex flex-wrap gap-2">
          {COLOR_ROLES.map(({ role, label }) => {
            const tile = palette.find((p) => p.role === role) || { hex: '#6366f1' }
            return (
              <button
                key={role}
                type="button"
                onClick={() => !disabled && onColorRoleChange?.(role)}
                className={`w-14 h-14 rounded-lg border-2 flex flex-col items-center justify-center text-xs font-medium transition-colors ${
                  selectedColorRole === role ? 'border-indigo-600 ring-2 ring-indigo-600 ring-offset-1' : 'border-gray-200 hover:border-gray-300'
                }`}
                style={{ backgroundColor: tile.hex }}
                disabled={disabled}
                title={label}
              >
                <span className="sr-only">{label}</span>
                <span className="mt-1 text-[10px] text-white drop-shadow-md" style={{ textShadow: '0 0 1px #000' }}>
                  {label}
                </span>
              </button>
            )
          })}
        </div>
      </div>

      {/* Background images — D10.1: Mini Asset Picker (Photography/Graphics, ≥1920×1080) */}
      <div>
        <label className="block text-sm font-medium leading-6 text-gray-900 mb-2">Background images (max {maxBackgrounds})</label>
        <div className="flex flex-wrap gap-2 items-center">
          {(backgroundAssets || []).map((a) => (
            <div key={a.id} className="relative group">
              <div className="w-16 h-16 rounded-lg border border-gray-200 overflow-hidden bg-gray-100">
                {a.thumbnail_url ? (
                  <img src={a.thumbnail_url} alt="" className="w-full h-full object-cover" />
                ) : (
                  <span className="text-xs text-gray-400 flex items-center justify-center h-full">—</span>
                )}
              </div>
              {!disabled && (
                <button
                  type="button"
                  onClick={() => onRemoveBackground?.(a.id)}
                  className="absolute -top-1 -right-1 w-5 h-5 rounded-full bg-red-500 text-white flex items-center justify-center opacity-90 hover:opacity-100 shadow"
                  aria-label="Remove"
                >
                  <XMarkIcon className="w-3 h-3" />
                </button>
              )}
            </div>
          ))}
          {!disabled && (
            <button
              type="button"
              onClick={() => setShowMiniPicker(true)}
              className="rounded-lg border-2 border-dashed border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 hover:border-indigo-400 hover:text-indigo-600"
            >
              Select background images
            </button>
          )}
        </div>
        <p className="mt-1 text-xs text-gray-500">Random image shown per visit.</p>

        <MiniAssetPicker
          open={showMiniPicker}
          onClose={() => setShowMiniPicker(false)}
          fetchAssets={fetchBackgroundCandidates}
          maxSelection={maxBackgrounds}
          initialSelectedIds={backgroundAssetIds}
          disabledAssetReason={disabledAssetReason}
          onConfirm={(ids) => {
            onBackgroundsConfirm?.(ids)
            setShowMiniPicker(false)
          }}
          title="Select background images"
        />
      </div>

      {/* Default copy */}
      <div>
        <label htmlFor="download_default_headline" className="block text-sm font-medium leading-6 text-gray-900">Default headline</label>
        <input
          id="download_default_headline"
          type="text"
          value={defaultHeadline}
          onChange={(e) => onHeadlineChange?.(e.target.value)}
          placeholder="e.g. Press Kit"
          disabled={disabled}
          className="mt-1 block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm disabled:opacity-60"
        />
      </div>
      <div>
        <label htmlFor="download_default_subtext" className="block text-sm font-medium leading-6 text-gray-900">Default subtext</label>
        <input
          id="download_default_subtext"
          type="text"
          value={defaultSubtext}
          onChange={(e) => onSubtextChange?.(e.target.value)}
          placeholder="e.g. Approved brand assets"
          disabled={disabled}
          className="mt-1 block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm disabled:opacity-60"
        />
      </div>
    </div>
  )
}
