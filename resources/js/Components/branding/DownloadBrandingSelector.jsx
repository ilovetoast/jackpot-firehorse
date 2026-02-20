/**
 * D10 — Reusable brand-level download landing branding selector.
 * D10.1: Background selection uses AssetImagePicker (Photography, ≥1920×1080).
 * Phase 1.1: Logo uses AssetImagePicker (Logos category).
 * Phase 1.3: Public page theme — Brand Mark, Accent Styling, Background Visuals.
 */
import { useState } from 'react'
import { XMarkIcon, PhotoIcon } from '@heroicons/react/24/outline'
import AssetImagePicker from '../media/AssetImagePicker'

const COLOR_ROLES = [
  { role: 'primary', label: 'Primary' },
  { role: 'secondary', label: 'Secondary' },
  { role: 'accent', label: 'Accent' },
]

export default function DownloadBrandingSelector({
  logoAssets = [],
  brandLogoPath = null,
  logoMode = 'brand', // 'brand' | 'custom' | 'none'
  selectedLogoAssetId = null,
  onLogoChange,
  fetchLogoAssets,
  primaryColor = '#6366f1',
  secondaryColor = '#64748b',
  accentColor = '#6366f1',
  selectedColorRole = 'primary',
  customColor = '',
  onColorRoleChange,
  onCustomColorChange,
  backgroundAssets = [],
  backgroundAssetIds = [],
  onRemoveBackground,
  onBackgroundsConfirm, // (assetIds: string[], assets?: Array<{ id, thumbnail_url?, original_filename? }>) => void
  fetchBackgroundCandidates,
  maxBackgrounds = 5,
  disabled = false,
}) {
  const [showLogoPicker, setShowLogoPicker] = useState(false)
  const [showMiniPicker, setShowMiniPicker] = useState(false)

  const palette = [
    { role: 'primary', label: 'Primary', hex: primaryColor || '#6366f1' },
    { role: 'secondary', label: 'Secondary', hex: secondaryColor || '#64748b' },
    { role: 'accent', label: 'Accent', hex: accentColor || '#6366f1' },
  ]

  return (
    <div className="space-y-10">
      {/* Brand Mark — Logo (brand identity), Choose from library, or No logo */}
      <div>
        <h4 className="text-sm font-medium text-gray-900 mb-1">Brand Mark</h4>
        <p className="text-sm text-gray-500 mb-4">
          Logo (brand identity), choose from library, or no logo.
        </p>
        <div className="flex flex-wrap gap-2 items-center">
          {/* Logo — brand identity (default) */}
          <button
            type="button"
            onClick={() => !disabled && onLogoChange?.('brand', null)}
            className={`w-16 h-16 rounded-lg border-2 flex flex-col items-center justify-center overflow-hidden transition-colors ${
              logoMode === 'brand'
                ? 'border-indigo-600 ring-2 ring-indigo-600 ring-offset-1 bg-indigo-50'
                : 'border-gray-200 bg-gray-50 hover:border-gray-300'
            }`}
            disabled={disabled}
            title="Use brand identity logo"
          >
            {brandLogoPath ? (
              <>
                <img src={brandLogoPath} alt="" className="w-10 h-10 object-contain flex-shrink-0" />
                <span className={`text-[10px] font-medium leading-tight ${logoMode === 'brand' ? 'text-indigo-700' : 'text-gray-500'}`}>Logo</span>
              </>
            ) : (
              <span className={`text-xs font-medium ${logoMode === 'brand' ? 'text-indigo-700' : 'text-gray-500'}`}>Logo</span>
            )}
          </button>
          {/* No logo */}
          <button
            type="button"
            onClick={() => !disabled && onLogoChange?.('none', null)}
            className={`w-16 h-16 rounded-lg border-2 flex flex-col items-center justify-center overflow-hidden transition-colors ${
              logoMode === 'none'
                ? 'border-indigo-600 ring-2 ring-indigo-600 ring-offset-1 bg-indigo-50'
                : 'border-gray-200 bg-gray-50 hover:border-gray-300'
            }`}
            disabled={disabled}
            title="No logo"
          >
            <span className={`text-xs font-medium ${logoMode === 'none' ? 'text-indigo-700' : 'text-gray-500'}`}>No logo</span>
          </button>
          {logoMode === 'custom' && selectedLogoAssetId != null && (() => {
            const sel = (logoAssets || []).find((a) => a.id === selectedLogoAssetId)
            return sel ? (
              <div key={sel.id} className="relative">
                <div className="w-16 h-16 rounded-lg border-2 border-indigo-600 ring-2 ring-indigo-600 ring-offset-1 overflow-hidden flex-shrink-0">
                  {sel.thumbnail_url ? (
                    <img src={sel.thumbnail_url} alt="" className="w-full h-full object-contain bg-gray-300" />
                  ) : (
                    <span className="text-xs text-gray-400 flex items-center justify-center h-full">Logo</span>
                  )}
                </div>
                {!disabled && (
                  <button
                    type="button"
                    onClick={() => onLogoChange?.('brand', null)}
                    className="absolute -top-1 -right-1 w-5 h-5 rounded-full bg-red-500 text-white flex items-center justify-center opacity-90 hover:opacity-100 shadow"
                    aria-label="Remove logo"
                  >
                    <XMarkIcon className="w-3 h-3" />
                  </button>
                )}
              </div>
            ) : (
              <div className="w-16 h-16 rounded-lg border-2 border-indigo-600 ring-2 ring-indigo-600 ring-offset-1 flex items-center justify-center text-xs text-gray-500 bg-gray-50">
                Selected
              </div>
            )
          })()}
          {fetchLogoAssets && !disabled && (
            <button
              type="button"
              onClick={() => setShowLogoPicker(true)}
              className={`rounded-lg border-2 border-dashed px-3 py-2 text-sm font-medium flex items-center gap-2 transition-colors ${
                logoMode === 'custom'
                  ? 'border-indigo-600 ring-2 ring-indigo-600 ring-offset-1 bg-indigo-50 text-indigo-700'
                  : 'border-gray-300 text-gray-600 hover:border-indigo-400 hover:text-indigo-600'
              }`}
            >
              <PhotoIcon className="w-4 h-4" />
              Choose from library
            </button>
          )}
          {!fetchLogoAssets && (logoAssets || []).map((a) => (
            <button
              key={a.id}
              type="button"
              onClick={() => !disabled && onLogoChange?.('custom', a.id)}
              className={`w-16 h-16 rounded-lg border-2 overflow-hidden flex-shrink-0 transition-colors ${
                selectedLogoAssetId === a.id
                  ? 'border-indigo-600 ring-2 ring-indigo-600 ring-offset-1'
                  : 'border-gray-200 hover:border-gray-300'
              }`}
              disabled={disabled}
              title={a.original_filename || 'Logo'}
            >
              {a.thumbnail_url ? (
                <img src={a.thumbnail_url} alt="" className="w-full h-full object-contain bg-gray-300" />
              ) : (
                <span className="text-xs text-gray-400 p-1">Logo</span>
              )}
            </button>
          ))}
        </div>
        {fetchLogoAssets && (
          <AssetImagePicker
            open={showLogoPicker}
            onClose={() => setShowLogoPicker(false)}
            fetchAssets={fetchLogoAssets}
            onSelect={(result) => {
              if (result.asset_id) onLogoChange?.('custom', result.asset_id)
              setShowLogoPicker(false)
            }}
            title="Select logo"
            defaultCategoryLabel="Logos"
            contextCategory="logos"
            aspectRatio={{ width: 265, height: 64 }}
            minWidth={100}
            minHeight={40}
          />
        )}
      </div>

      {/* Accent Styling — same component as Workspace color selector */}
      <div>
        <h4 className="text-sm font-medium text-gray-900 mb-1">Accent Styling</h4>
        <p className="text-sm text-gray-500 mb-4">
          Choose a color from your brand palette for CTAs and accents.
        </p>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
          {COLOR_ROLES.map(({ role, label }) => {
            const tile = palette.find((p) => p.role === role) || { hex: '#6366f1' }
            const hasColor = role === 'primary' || (role === 'secondary' && secondaryColor) || (role === 'accent' && accentColor)
            return hasColor ? (
              <button
                key={role}
                type="button"
                onClick={() => !disabled && onColorRoleChange?.(role)}
                className={`relative flex flex-col items-center p-3 rounded-lg border-2 transition-all ${
                  selectedColorRole === role ? 'border-indigo-600 ring-2 ring-indigo-600' : 'border-gray-200 hover:border-gray-300'
                }`}
                disabled={disabled}
                title={label}
              >
                <div className="w-full h-12 rounded-md mb-1.5" style={{ backgroundColor: tile.hex }} />
                <span className="text-xs font-medium text-gray-900">{label}</span>
                {selectedColorRole === role && (
                  <div className="absolute top-1.5 right-1.5">
                    <svg className="h-4 w-4 text-indigo-600" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" /></svg>
                  </div>
                )}
              </button>
            ) : (
              <div key={role} className="flex flex-col items-center p-3 rounded-lg border-2 border-gray-100 opacity-50">
                <div className="w-full h-12 rounded-md mb-1.5 bg-gray-50 border-2 border-dashed border-gray-200" />
                <span className="text-xs font-medium text-gray-400">{label}</span>
              </div>
            )
          })}
          {/* Custom option — same as Workspace */}
          <button
            type="button"
            onClick={() => !disabled && onColorRoleChange?.('custom')}
            className={`relative flex flex-col items-center p-3 rounded-lg border-2 transition-all ${
              selectedColorRole === 'custom' ? 'border-indigo-600 ring-2 ring-indigo-600' : 'border-gray-200 hover:border-gray-300'
            }`}
            disabled={disabled}
            title="Custom"
          >
            <div
              className="w-full h-12 rounded-md mb-1.5 border-2 border-dashed border-gray-300 flex items-center justify-center"
              style={{ backgroundColor: (selectedColorRole === 'custom' && (customColor || primaryColor)) ? (customColor || primaryColor) : '#f3f4f6' }}
            >
              {selectedColorRole === 'custom' && (customColor || primaryColor) ? null : (
                <svg className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" /></svg>
              )}
            </div>
            <span className="text-xs font-medium text-gray-900">Custom</span>
            {selectedColorRole === 'custom' && (
              <div className="absolute top-1.5 right-1.5">
                <svg className="h-4 w-4 text-indigo-600" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" /></svg>
              </div>
            )}
          </button>
        </div>
        {selectedColorRole === 'custom' && (
          <div className="mt-3 flex gap-2 items-center">
            <input
              type="color"
              value={(customColor || primaryColor || '#6366f1').startsWith('#') ? (customColor || primaryColor || '#6366f1') : '#' + (customColor || primaryColor || '6366f1')}
              onChange={(e) => onCustomColorChange?.(e.target.value)}
              className="h-8 w-14 rounded border border-gray-300 cursor-pointer flex-shrink-0"
            />
            <input
              type="text"
              value={customColor || ''}
              onChange={(e) => onCustomColorChange?.(e.target.value)}
              className="block w-24 rounded-md border py-1.5 px-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600"
              placeholder="#6366f1"
            />
          </div>
        )}
      </div>

      {/* Background Visuals */}
      <div>
        <h4 className="text-sm font-medium text-gray-900 mb-1">Background Visuals</h4>
        <p className="text-sm text-gray-500 mb-4">
          Background images (max {maxBackgrounds}). Randomized per visit.
        </p>
        <div className="flex flex-wrap gap-3 items-center">
          {!disabled && (
            <button
              type="button"
              onClick={() => setShowMiniPicker(true)}
              className="rounded-lg border-2 border-dashed border-gray-300 px-4 py-3 text-sm font-medium text-gray-600 hover:border-indigo-400 hover:text-indigo-600 flex items-center gap-2"
            >
              <PhotoIcon className="w-4 h-4" />
              Choose from library
            </button>
          )}
          {(backgroundAssets || []).map((a) => (
            <div key={a.id} className="relative group">
              <div className="w-16 h-16 rounded-lg border border-gray-200 overflow-hidden bg-gray-100 shadow-sm">
                {(a.thumbnail_url || a.url) ? (
                  <img src={a.thumbnail_url || a.url} alt="" className="w-full h-full object-cover" />
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
        </div>
        <p className="mt-2 text-xs text-gray-500">
          {(backgroundAssets || []).length}/{maxBackgrounds} selected
        </p>

        <AssetImagePicker
          open={showMiniPicker}
          onClose={() => setShowMiniPicker(false)}
          fetchAssets={fetchBackgroundCandidates}
          onSelect={(result) => {
            const ids = result?.asset_ids
            if (ids != null) {
              onBackgroundsConfirm?.(ids, result?.assets)
            }
            setShowMiniPicker(false)
          }}
          title="Select background images"
          defaultCategoryLabel="Photography"
          contextCategory="photography"
          maxSelection={maxBackgrounds}
          singleSelect={false}
          initialSelectedIds={backgroundAssetIds}
        />
      </div>

      {/* TODO: Remove deprecated public page headline/subtext fields in future migration. */}
    </div>
  )
}
