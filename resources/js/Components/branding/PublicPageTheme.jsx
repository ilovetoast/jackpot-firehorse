/**
 * Public Page Theme — Configuration + Preview.
 * Manages background visuals with full asset objects for immediate thumbnail display.
 * Does NOT rely on persisted brand data until save.
 */
import { useState, useEffect, useRef } from 'react'
import { flushSync } from 'react-dom'
import DownloadBrandingSelector from './DownloadBrandingSelector'
import PublicPageThemePreview from './PublicPageThemePreview'

function getThumbnailUrl(asset) {
  return asset?.thumbnail_url ?? asset?.final_thumbnail_url ?? asset?.preview_thumbnail_url ?? asset?.url ?? null
}

export default function PublicPageTheme({
  brand,
  data,
  setData,
  route,
}) {
  const [showBackgroundPicker, setShowBackgroundPicker] = useState(false)
  // Full asset objects for immediate display — not persisted until save
  const [selectedBackgroundAssets, setSelectedBackgroundAssets] = useState([])
  const justConfirmedRef = useRef(false)

  const backgroundAssetIds = data.download_landing_settings?.background_asset_ids || []

  // Clear local assets when IDs become empty (e.g. user cleared elsewhere).
  // Skip clear right after confirm — setData may not have updated form data yet, causing a race.
  useEffect(() => {
    if (justConfirmedRef.current) {
      justConfirmedRef.current = false
      return
    }
    if (backgroundAssetIds.length === 0) {
      setSelectedBackgroundAssets([])
    }
  }, [backgroundAssetIds.length])

  const handleBackgroundsConfirm = (ids, assets) => {
    const idsArray = Array.isArray(ids) ? ids : []
    // Use full assets when available; otherwise build minimal objects with thumbnail URLs for display
    const resolved = Array.isArray(assets) && assets.length > 0
      ? assets
      : idsArray.map((id) => {
          const thumbUrl = typeof route === 'function'
            ? route('assets.thumbnail.final', { asset: id, style: 'medium' })
            : `/app/assets/${id}/thumbnail/final/medium`
          return { id, thumbnail_url: thumbUrl, url: thumbUrl }
        })

    // Force local state to commit BEFORE form update so thumbnails render immediately
    justConfirmedRef.current = true
    flushSync(() => {
      setSelectedBackgroundAssets(resolved)
    })

    // Merge to preserve other download_landing_settings fields (logo, color_role, etc.)
    setData('download_landing_settings', {
      ...(data.download_landing_settings || {}),
      background_asset_ids: idsArray,
    })
  }

  const handleRemoveBackground = (id) => {
    const ids = backgroundAssetIds.filter((x) => String(x) !== String(id))
    setData('download_landing_settings', {
      ...(data.download_landing_settings || {}),
      background_asset_ids: ids,
    })
    setSelectedBackgroundAssets((prev) => prev.filter((a) => a?.id != null && String(a.id) !== String(id)))
  }

  // Build display assets: prefer local full objects; when we have IDs but no local assets (e.g. from save, or lost state), hydrate from IDs
  const displayAssets = (() => {
    if (selectedBackgroundAssets.length > 0) return selectedBackgroundAssets
    if (backgroundAssetIds.length === 0) return []
    // Hydrate from IDs so thumbnails show when form has IDs but local state is empty (page load after save, or state loss)
    return backgroundAssetIds.map((id) => {
      const thumbUrl = typeof route === 'function'
        ? route('assets.thumbnail.final', { asset: id, style: 'medium' })
        : `/app/assets/${id}/thumbnail/final/medium`
      return { id, thumbnail_url: thumbUrl, url: thumbUrl }
    })
  })()

  const heroBackgroundUrl = displayAssets.length > 0 ? getThumbnailUrl(displayAssets[0]) : null

  return (
    <div className="mt-10 grid grid-cols-1 lg:grid-cols-2 gap-12">
      {/* Left: Theme configuration */}
      <div className="space-y-10">
        <DownloadBrandingSelector
          logoAssets={brand.logo_assets || []}
          brandLogoPath={brand.logo_path}
          selectedLogoAssetId={data.download_landing_settings?.logo_asset_id ?? null}
          onLogoChange={(id) => setData('download_landing_settings.logo_asset_id', id)}
          fetchLogoAssets={(opts) => {
            const params = new URLSearchParams({ format: 'json' })
            if (opts?.category) params.set('category', opts.category)
            return fetch(`/app/assets?${params}`, {
              credentials: 'same-origin',
              headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            }).then((r) => r.json())
          }}
          primaryColor={data.primary_color || '#6366f1'}
          secondaryColor={data.secondary_color || '#64748b'}
          accentColor={data.accent_color || '#6366f1'}
          selectedColorRole={data.download_landing_settings?.color_role || 'primary'}
          customColor={data.download_landing_settings?.custom_color || ''}
          onColorRoleChange={(role) => setData('download_landing_settings.color_role', role)}
          onCustomColorChange={(hex) => setData('download_landing_settings.custom_color', hex)}
          backgroundAssets={displayAssets}
          backgroundAssetIds={backgroundAssetIds}
          onRemoveBackground={handleRemoveBackground}
          onBackgroundsConfirm={handleBackgroundsConfirm}
          fetchBackgroundCandidates={(opts) => {
            const usePhotography = opts?.category === 'photography'
            if (usePhotography) {
              const url = typeof route === 'function' ? route('brands.download-background-candidates', brand.id) : `/app/brands/${brand.id}/download-background-candidates`
              return fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
              }).then((r) => r.json()).then((res) => {
                const list = res?.assets ?? res?.data ?? res ?? []
                return Array.isArray(list) ? list : []
              }).catch(() => fetch('/app/assets?category=photography&format=json', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
              }).then((r) => r.json()))
            }
            const params = new URLSearchParams({ format: 'json' })
            if (opts?.category) params.set('category', opts.category)
            return fetch(`/app/assets?${params}`, {
              credentials: 'same-origin',
              headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            }).then((r) => r.json())
          }}
          maxBackgrounds={5}
        />
      </div>
      {/* Right: Theme preview panel */}
      <div>
        <h4 className="text-sm font-medium text-gray-900 mb-1">Preview</h4>
        <p className="text-sm text-gray-500 mb-4">
          Simulated public page layout.
        </p>
        <PublicPageThemePreview
          logoUrl={(() => {
            const lid = data.download_landing_settings?.logo_asset_id
            if (lid && typeof route === 'function') {
              return route('assets.thumbnail.final', { asset: lid, style: 'medium_display' })
            }
            return brand.logo_path
          })()}
          logoUrlFallback={(() => {
            const lid = data.download_landing_settings?.logo_asset_id
            if (lid && typeof route === 'function') {
              return route('assets.thumbnail.final', { asset: lid, style: 'medium' })
            }
            return null
          })()}
          accentColor={(() => {
            const role = data.download_landing_settings?.color_role || 'primary'
            if (role === 'primary') return data.primary_color || '#6366f1'
            if (role === 'secondary') return data.secondary_color || '#64748b'
            if (role === 'custom') return data.download_landing_settings?.custom_color || data.primary_color || '#6366f1'
            return data.accent_color || '#6366f1'
          })()}
          backgroundUrl={heroBackgroundUrl}
        />
      </div>
    </div>
  )
}
