/**
 * AssetImagePicker — Reusable asset-backed image picker with upload and crop.
 *
 * Use for: Brand logo, Icon, Landing page logo, Landing page photography.
 *
 * Capabilities:
 * - Open modal, show existing assets (via fetchAssets)
 * - Contextual tabs: Context (filtered) | Browse All | Upload when contextCategory set
 * - Default tabs: Browse All | Upload when no contextCategory
 * - Allow upload inside modal
 * - Allow cropping (reuse company logo crop logic)
 *
 * Returns: { asset_id } if existing asset selected, or { file } if new upload.
 * Parent decides how to handle each case.
 *
 * fetchAssets(opts?: { category?: string | null }) => Promise<Asset[]>
 * - category: 'logos' | 'icons' | 'photography' for contextual tab; null for Browse All
 * - Do NOT pass load_more for initial fetch; only when explicitly paginating.
 */
import { useState, useEffect, useRef, useCallback } from 'react'
import { XMarkIcon, PhotoIcon, CloudArrowUpIcon, CheckIcon } from '@heroicons/react/24/outline'
import ImageCropModal from '../ImageCropModal'

const ACCEPT_IMAGE = 'image/png,image/webp,image/svg+xml,image/avif,image/jpeg,image/jpg'

const CONTEXT_LABELS = {
  logos: 'Logos',
  icons: 'Icons',
  photography: 'Photography',
}

export default function AssetImagePicker({
  open,
  onClose,
  fetchAssets,
  fetchDeliverables = null, // optional: when provided with fetchAssets, shows Assets | Deliverables source toggle
  onSelect,
  title = 'Select image',
  defaultCategoryLabel = 'Logos',
  contextCategory = null, // 'logos' | 'icons' | 'photography' | null
  aspectRatio = null,
  minWidth = 100,
  minHeight = 100,
  acceptFileTypes = ACCEPT_IMAGE,
  disabled = false,
  singleSelect = true,
  maxSelection = 1,
  initialSelectedIds = [],
  getAssetDownloadUrl = null,
}) {
  const isMulti = maxSelection > 1
  const hasSourceToggle = !!(fetchAssets && fetchDeliverables)
  const [source, setSource] = useState(() => (fetchAssets ? 'assets' : 'deliverables'))
  const [assets, setAssets] = useState([])
  const [categories, setCategories] = useState([])
  const [loading, setLoading] = useState(false)
  const [loadingMore, setLoadingMore] = useState(false)
  const [nextPageUrl, setNextPageUrl] = useState(null)
  const [selectedAssetId, setSelectedAssetId] = useState(null)
  const [selectedAssetIds, setSelectedAssetIds] = useState([])
  const [uploadedFile, setUploadedFile] = useState(null)
  const [uploadedFilePreviewUrl, setUploadedFilePreviewUrl] = useState(null)
  const [cropModalOpen, setCropModalOpen] = useState(false)
  const [imageToCrop, setImageToCrop] = useState(null)
  // mode: 'context' | 'browse' | 'upload'
  const [mode, setMode] = useState('context')
  // When in browse mode: filter by category slug (null = all)
  const [browseCategoryFilter, setBrowseCategoryFilter] = useState(null)

  const effectiveFetch = source === 'deliverables' ? fetchDeliverables : fetchAssets
  const fetchAssetsRef = useRef(effectiveFetch)
  const lastFetchedKeyRef = useRef(null)
  const openCountRef = useRef(0)

  fetchAssetsRef.current = effectiveFetch

  const contextLabel = contextCategory ? (CONTEXT_LABELS[contextCategory] ?? defaultCategoryLabel) : defaultCategoryLabel

  const doFetch = useCallback((category) => {
    const fn = fetchAssetsRef.current
    if (!fn) return Promise.resolve({ assets: [], categories: [] })
    const opts = { category }
    const result = fn(opts)
    if (!result || typeof result.then !== 'function') return Promise.resolve({ assets: [], categories: [] })
    return result.then((res) => {
      const list = res?.props?.assets ?? res?.data ?? res?.assets ?? (Array.isArray(res) ? res : [])
      const categories = res?.props?.categories ?? res?.categories ?? res?.props?.categories_by_type?.all ?? res?.categories_by_type?.all ?? []
      const nextPageUrl = res?.next_page_url ?? null
      return {
        assets: Array.isArray(list) ? list : [],
        categories: Array.isArray(categories) ? categories : [],
        next_page_url: nextPageUrl,
      }
    }).catch(() => ({ assets: [], categories: [], next_page_url: null }))
  }, [])

  // Reset state when modal opens. Do NOT include fetchAssets or initialSelectedIds in deps —
  // they are often inline functions/arrays from parent, causing "Maximum update depth exceeded".
  useEffect(() => {
    if (!open) {
      lastFetchedKeyRef.current = null
      return
    }

    openCountRef.current += 1

    setSelectedAssetId(null)
    const ids = Array.isArray(initialSelectedIds) ? initialSelectedIds.slice(0, maxSelection) : []
    setSelectedAssetIds(ids)
    setUploadedFile(null)
    setUploadedFilePreviewUrl((prev) => {
      if (prev && prev.startsWith('blob:')) URL.revokeObjectURL(prev)
      return null
    })
    setBrowseCategoryFilter(null)
    setNextPageUrl(null)
    if (hasSourceToggle) setSource('assets')

    if (!fetchAssets && !fetchDeliverables) {
      setMode('upload')
      return
    }

    setMode(contextCategory ? 'context' : 'browse')
    lastFetchedKeyRef.current = null
  }, [open, contextCategory, maxSelection])

  useEffect(() => {
    if (!open || mode === 'upload') return

    const category = mode === 'context' && contextCategory ? contextCategory : browseCategoryFilter
    const fetchKey = `${source}-${mode}-${category ?? 'all'}`
    if (lastFetchedKeyRef.current === fetchKey) return
    lastFetchedKeyRef.current = fetchKey

    setLoading(true)
    setAssets([])

    const thisOpenId = openCountRef.current
    if (typeof process !== 'undefined' && process.env?.NODE_ENV === 'development') {
      console.debug('[AssetImagePicker] Fetching assets', { mode, category, openCount: thisOpenId })
    }

    doFetch(category)
      .then(({ assets: arr, categories: cats, next_page_url: next }) => {
        if (thisOpenId !== openCountRef.current) return
        setAssets(arr)
        setNextPageUrl(next || null)
        if (mode === 'browse' && Array.isArray(cats) && cats.length > 0) {
          setCategories(cats)
        }
      })
      .catch(() => {
        if (thisOpenId !== openCountRef.current) return
        setAssets([])
      })
      .finally(() => {
        if (thisOpenId !== openCountRef.current) return
        setLoading(false)
      })
  }, [open, source, mode, contextCategory, browseCategoryFilter, doFetch])

  const handleSelectAsset = (asset) => {
    if (isMulti) {
      setSelectedAssetIds((prev) => {
        const id = asset.id
        if (prev.includes(id)) return prev.filter((x) => x !== id)
        if (prev.length >= maxSelection) return prev
        return [...prev, id]
      })
      setUploadedFile(null)
    } else {
      setSelectedAssetId(asset.id)
      setUploadedFile(null)
    }
  }

  const handleFileChange = (e) => {
    const file = e.target.files?.[0]
    if (!file) return
    if (file.type === 'image/svg+xml') {
      setUploadedFilePreviewUrl((prev) => {
        if (prev && prev.startsWith('blob:')) URL.revokeObjectURL(prev)
        return URL.createObjectURL(file)
      })
      setUploadedFile(file)
      setSelectedAssetId(null)
    } else {
      setImageToCrop(URL.createObjectURL(file))
      setCropModalOpen(true)
    }
    e.target.value = ''
  }

  const handleCropComplete = (croppedBlob) => {
    const file = new File([croppedBlob], 'image.png', { type: 'image/png' })
    setUploadedFilePreviewUrl((prev) => {
      if (prev && prev.startsWith('blob:')) URL.revokeObjectURL(prev)
      return URL.createObjectURL(file)
    })
    setUploadedFile(file)
    setSelectedAssetId(null)
    if (imageToCrop && imageToCrop.startsWith('blob:')) {
      URL.revokeObjectURL(imageToCrop)
    }
    setImageToCrop(null)
    setCropModalOpen(false)
  }

  const handleConfirm = async () => {
    if (isMulti && selectedAssetIds.length > 0) {
      // Use loose equality (==) to handle number/string id mismatch from API vs form state
      const selectedAssets = selectedAssetIds
        .map((id) => assets.find((a) => a.id == id || String(a?.id) === String(id)))
        .filter(Boolean)
      onSelect?.({ asset_ids: selectedAssetIds, assets: selectedAssets })
      onClose()
      return
    }
    if (!isMulti && selectedAssetId) {
      const asset = assets.find((a) => a.id === selectedAssetId)
      const thumb = asset?.thumbnail_url ?? asset?.final_thumbnail_url ?? asset?.preview_thumbnail_url
      if (getAssetDownloadUrl) {
        try {
          const url = getAssetDownloadUrl(selectedAssetId)
          const res = await fetch(url, { credentials: 'same-origin' })
          const blob = await res.blob()
          const previewUrl = URL.createObjectURL(blob)
          // Pass asset_id so parent can persist; preview_url for immediate display (blob avoids 404 until thumbnails ready)
          onSelect?.({ asset_id: selectedAssetId, thumbnail_url: thumb ?? null, preview_url: previewUrl })
        } catch (err) {
          console.error('Failed to fetch asset file:', err)
          onSelect?.({ asset_id: selectedAssetId, thumbnail_url: thumb ?? null })
        }
      } else {
        onSelect?.({ asset_id: selectedAssetId, thumbnail_url: thumb ?? null })
      }
      onClose()
      return
    }
    if (uploadedFile) {
      // Upload through asset pipeline with context-based category (logos, icons, photography)
      const categorySlug = contextCategory === 'icons' ? 'logos' : (contextCategory || 'logos')
      const cat = categories.find((c) => (c.slug || c.name?.toLowerCase()) === categorySlug)
      const categoryId = cat?.id ?? null
      if (categoryId) {
        try {
          setLoading(true)
          const csrf = document.querySelector('meta[name="csrf-token"]')?.content
          const initRes = await fetch('/app/uploads/initiate-batch', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
              files: [{ file_name: uploadedFile.name, file_size: uploadedFile.size, mime_type: uploadedFile.type }],
              category_id: categoryId,
            }),
          })
          if (!initRes.ok) throw new Error(`Initiate failed: ${initRes.status}`)
          const initData = await initRes.json()
          const result = initData.uploads?.[0]
          if (!result?.upload_session_id) throw new Error('No upload session returned')
          if (result.upload_type === 'direct' && result.upload_url) {
            const putRes = await fetch(result.upload_url, {
              method: 'PUT',
              headers: { 'Content-Type': uploadedFile.type || 'application/octet-stream' },
              body: uploadedFile,
            })
            if (!putRes.ok) throw new Error(`Upload failed: ${putRes.status}`)
          } else {
            throw new Error('Direct upload required for settings; file may be too large')
          }
          const finalRes = await fetch('/app/assets/upload/finalize', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
              manifest: [{
                upload_key: `temp/uploads/${result.upload_session_id}/original`,
                expected_size: uploadedFile.size,
                category_id: categoryId,
                resolved_filename: uploadedFile.name,
              }],
            }),
          })
          if (!finalRes.ok) throw new Error(`Finalize failed: ${finalRes.status}`)
          const finalData = await finalRes.json()
          const assetResult = finalData.results?.[0]
          const assetId = assetResult?.asset_id ?? assetResult?.id
          if (assetId) {
            const thumb = assetResult?.thumbnail_url ?? assetResult?.final_thumbnail_url ?? `/app/assets/${assetId}/thumbnail/final/medium`
            // New assets don't have thumbnails yet (generated async) - pass blob preview for immediate display
            const immediatePreview = uploadedFilePreviewUrl || (uploadedFile ? URL.createObjectURL(uploadedFile) : null)
            onSelect?.({ asset_id: assetId, thumbnail_url: thumb, preview_url: immediatePreview })
            onClose()
            return
          }
        } catch (err) {
          console.error('[AssetImagePicker] Upload to pipeline failed:', err)
          // Do not fall back to file - all logos/icons must be stored as assets
        } finally {
          setLoading(false)
        }
      }
      // No fallback: upload must succeed. User can retry or select from library.
    }
    onClose()
  }

  const handleClose = () => {
    if (imageToCrop && imageToCrop.startsWith('blob:')) {
      URL.revokeObjectURL(imageToCrop)
    }
    setImageToCrop(null)
    setCropModalOpen(false)
    onClose()
  }

  const handleTabSwitch = (newMode) => {
    setMode(newMode)
    lastFetchedKeyRef.current = null
  }

  const handleLoadMore = () => {
    if (!nextPageUrl || loadingMore) return
    setLoadingMore(true)
    fetch(nextPageUrl, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then((r) => r.json())
      .then((res) => {
        const list = res?.data ?? res?.assets ?? []
        const next = res?.next_page_url ?? null
        setAssets((prev) => [...prev, ...(Array.isArray(list) ? list : [])])
        setNextPageUrl(next)
      })
      .catch(() => {})
      .finally(() => setLoadingMore(false))
  }

  const canConfirm = (isMulti && selectedAssetIds.length > 0) || (!isMulti && (selectedAssetId || uploadedFile))

  const emptyLabel = mode === 'context' ? contextLabel : 'library'

  if (!open) return null

  const hasContextTab = !!contextCategory

  return (
    <>
      <div className="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
        <div className="flex min-h-full items-center justify-center p-4">
          <div className="fixed inset-0 bg-black/50" onClick={handleClose} aria-hidden />
          <div className="relative bg-white rounded-xl shadow-xl max-w-4xl w-full max-h-[85vh] overflow-hidden flex flex-col">
            <div className="px-5 py-4 border-b border-gray-200/80 flex items-center justify-between flex-shrink-0">
              <h3 className="text-base font-semibold text-gray-900">{title}</h3>
              <button
                type="button"
                onClick={handleClose}
                className="text-gray-400 hover:text-gray-600 p-1.5 rounded-lg hover:bg-gray-100 transition-colors"
                aria-label="Close"
              >
                <XMarkIcon className="w-5 h-5" />
              </button>
            </div>

            {/* Source toggle: Assets | Deliverables (when both fetch functions provided) */}
            {hasSourceToggle && (
              <div className="flex gap-2 px-5 py-2 border-b border-gray-100">
                <span className="text-xs font-medium text-gray-500 uppercase tracking-wide self-center">Source:</span>
                <div className="flex rounded-lg bg-gray-100 p-0.5">
                  <button
                    type="button"
                    onClick={() => { setSource('assets'); lastFetchedKeyRef.current = null }}
                    className={`px-3 py-1.5 text-sm font-medium rounded-md transition-colors ${
                      source === 'assets' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'
                    }`}
                  >
                    Assets
                  </button>
                  <button
                    type="button"
                    onClick={() => { setSource('deliverables'); lastFetchedKeyRef.current = null }}
                    className={`px-3 py-1.5 text-sm font-medium rounded-md transition-colors ${
                      source === 'deliverables' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'
                    }`}
                  >
                    Executions
                  </button>
                </div>
              </div>
            )}

            {/* Tabs: Context | Browse All | Upload (when contextCategory) or Browse All | Upload */}
            <div className="flex border-b border-gray-200/80 px-5">
              {(fetchAssets || fetchDeliverables) && hasContextTab && (
                <button
                  type="button"
                  onClick={() => handleTabSwitch('context')}
                  className={`px-4 py-3 text-sm font-medium border-b-2 -mb-px transition-colors ${
                    mode === 'context'
                      ? 'border-indigo-600 text-indigo-600'
                      : 'border-transparent text-gray-500 hover:text-gray-700'
                  }`}
                >
                  <PhotoIcon className="w-4 h-4 inline-block mr-2 -mt-0.5" />
                  {contextLabel}
                  {isMulti && mode === 'context' && (
                    <span className="ml-2 text-xs text-gray-500">
                      ({selectedAssetIds.length}/{maxSelection})
                    </span>
                  )}
                </button>
              )}
              {(fetchAssets || fetchDeliverables) && (
                <button
                  type="button"
                  onClick={() => handleTabSwitch('browse')}
                  className={`px-4 py-3 text-sm font-medium border-b-2 -mb-px transition-colors ${
                    mode === 'browse'
                      ? 'border-indigo-600 text-indigo-600'
                      : 'border-transparent text-gray-500 hover:text-gray-700'
                  }`}
                >
                  <PhotoIcon className="w-4 h-4 inline-block mr-2 -mt-0.5" />
                  Browse All
                  {isMulti && mode === 'browse' && (
                    <span className="ml-2 text-xs text-gray-500">
                      ({selectedAssetIds.length}/{maxSelection})
                    </span>
                  )}
                </button>
              )}
              {!isMulti && (
                <button
                  type="button"
                  onClick={() => setMode('upload')}
                  className={`px-4 py-3 text-sm font-medium border-b-2 -mb-px transition-colors ${
                    mode === 'upload' || !(fetchAssets || fetchDeliverables)
                      ? 'border-indigo-600 text-indigo-600'
                      : 'border-transparent text-gray-500 hover:text-gray-700'
                  }`}
                >
                  <CloudArrowUpIcon className="w-4 h-4 inline-block mr-2 -mt-0.5" />
                  Upload New
                </button>
              )}
            </div>

            <div className="p-5 overflow-y-auto flex-1 min-h-0 flex gap-4">
              {mode !== 'upload' ? (
                <>
                  {/* Category filter sidebar when Browse All */}
                  {mode === 'browse' && categories.length > 0 && (
                    <div className="flex-shrink-0 w-40 space-y-1">
                      <p className="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Filter by type</p>
                      <button
                        type="button"
                        onClick={() => setBrowseCategoryFilter(null)}
                        className={`block w-full text-left px-3 py-2 rounded-md text-sm transition-colors ${
                          browseCategoryFilter === null
                            ? 'bg-indigo-50 text-indigo-700 font-medium'
                            : 'text-gray-700 hover:bg-gray-100'
                        }`}
                      >
                        All
                      </button>
                      {categories.map((cat) => (
                        <button
                          key={cat.id ?? cat.slug}
                          type="button"
                          onClick={() => setBrowseCategoryFilter(cat.slug)}
                          className={`block w-full text-left px-3 py-2 rounded-md text-sm transition-colors ${
                            browseCategoryFilter === cat.slug
                              ? 'bg-indigo-50 text-indigo-700 font-medium'
                              : 'text-gray-700 hover:bg-gray-100'
                          }`}
                        >
                          {cat.name ?? cat.slug ?? 'Category'}
                        </button>
                      ))}
                    </div>
                  )}
                  <div className="flex-1 min-w-0">
                  {loading ? (
                    <p className="text-sm text-gray-500 py-8">Loading…</p>
                  ) : (
                    <div className="grid grid-cols-4 sm:grid-cols-5 md:grid-cols-6 gap-3">
                      {assets.map((asset) => {
                        const thumb = asset.thumbnail_url ?? asset.final_thumbnail_url ?? asset.preview_thumbnail_url
                        const isSelected = isMulti ? selectedAssetIds.includes(asset.id) : selectedAssetId === asset.id
                        return (
                          <button
                            key={asset.id}
                            type="button"
                            onClick={() => !disabled && handleSelectAsset(asset)}
                            disabled={disabled}
                            className={`relative rounded-lg border-2 overflow-hidden transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 ${
                              isSelected
                                ? 'border-indigo-600 ring-2 ring-indigo-600 ring-offset-1'
                                : 'border-gray-200 hover:border-gray-300'
                            }`}
                          >
                            <div className="aspect-square bg-gray-300">
                              {thumb ? (
                                <img src={thumb} alt="" className="w-full h-full object-contain" />
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
                          </button>
                        )
                      })}
                    </div>
                  )}
                  {!loading && assets.length === 0 && (
                    <p className="text-sm text-gray-500 py-8 text-center">
                      No assets in {emptyLabel} yet. Upload a new image above.
                    </p>
                  )}
                  {!loading && assets.length > 0 && nextPageUrl && (
                    <div className="mt-4 flex justify-center">
                      <button
                        type="button"
                        onClick={handleLoadMore}
                        disabled={loadingMore}
                        className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:pointer-events-none"
                      >
                        {loadingMore ? 'Loading…' : 'Load more'}
                      </button>
                    </div>
                  )}
                  </div>
                </>
              ) : uploadedFile ? (
                <div className="flex flex-col items-center justify-center rounded-xl border-2 border-indigo-200 bg-indigo-50/30 px-8 py-12 relative">
                  {loading ? (
                    <div className="absolute inset-0 flex flex-col items-center justify-center rounded-xl bg-white/90 z-10">
                      <div className="animate-spin rounded-full h-10 w-10 border-2 border-indigo-600 border-t-transparent" />
                      <p className="mt-3 text-sm font-medium text-gray-700">Uploading…</p>
                    </div>
                  ) : null}
                  {uploadedFilePreviewUrl ? (
                    <img
                      src={uploadedFilePreviewUrl}
                      alt=""
                      className="max-h-48 max-w-full object-contain rounded-lg bg-gray-300 border border-gray-200"
                    />
                  ) : (
                    <div className="max-h-48 w-48 rounded-lg bg-gray-300 border border-gray-200 flex items-center justify-center text-gray-500 text-sm">
                      Preview
                    </div>
                  )}
                  <p className="mt-4 text-sm font-medium text-gray-700">{uploadedFile.name}</p>
                  <p className="mt-1 text-xs text-gray-500">Ready to upload. Click Select below.</p>
                  <button
                    type="button"
                    onClick={() => {
                      setUploadedFilePreviewUrl((prev) => {
                        if (prev && prev.startsWith('blob:')) URL.revokeObjectURL(prev)
                        return null
                      })
                      setUploadedFile(null)
                    }}
                    disabled={loading}
                    className="mt-4 text-sm font-medium text-indigo-600 hover:text-indigo-800 disabled:opacity-50 disabled:pointer-events-none"
                  >
                    Choose different image
                  </button>
                </div>
              ) : (
                <div
                  className="relative flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-300 bg-gray-50/50 px-8 py-12 transition-colors hover:border-gray-400 hover:bg-gray-50"
                  onDragOver={(e) => {
                    e.preventDefault()
                    e.currentTarget.classList.add('border-indigo-400', 'bg-indigo-50/30')
                  }}
                  onDragLeave={(e) => {
                    e.currentTarget.classList.remove('border-indigo-400', 'bg-indigo-50/30')
                  }}
                  onDrop={(e) => {
                    e.preventDefault()
                    e.currentTarget.classList.remove('border-indigo-400', 'bg-indigo-50/30')
                    const file = e.dataTransfer.files?.[0]
                    if (file && file.type.startsWith('image/')) {
                      if (file.type === 'image/svg+xml') {
                        setUploadedFilePreviewUrl((prev) => {
                          if (prev && prev.startsWith('blob:')) URL.revokeObjectURL(prev)
                          return URL.createObjectURL(file)
                        })
                        setUploadedFile(file)
                        setSelectedAssetId(null)
                      } else {
                        setImageToCrop(URL.createObjectURL(file))
                        setCropModalOpen(true)
                      }
                    }
                  }}
                >
                  <input
                    type="file"
                    accept={acceptFileTypes}
                    onChange={handleFileChange}
                    className="absolute inset-0 w-full h-full cursor-pointer opacity-0"
                  />
                  <CloudArrowUpIcon className="w-12 h-12 text-gray-400 mx-auto" />
                  <p className="mt-3 text-sm font-medium text-gray-600">Click or drag to upload</p>
                  <p className="mt-1 text-xs text-gray-500">PNG, WebP, SVG, JPEG up to 2MB</p>
                </div>
              )}
            </div>

            <div className="px-5 py-4 border-t border-gray-200/80 flex justify-end gap-3 flex-shrink-0">
              <button
                type="button"
                onClick={handleClose}
                className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handleConfirm}
                disabled={!canConfirm || disabled || loading}
                className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50 disabled:pointer-events-none transition-colors"
              >
                Select
              </button>
            </div>
          </div>
        </div>
      </div>

      <ImageCropModal
        open={cropModalOpen}
        imageSrc={imageToCrop}
        onClose={() => {
          setCropModalOpen(false)
          if (imageToCrop && imageToCrop.startsWith('blob:')) {
            URL.revokeObjectURL(imageToCrop)
          }
          setImageToCrop(null)
        }}
        onCropComplete={handleCropComplete}
        aspectRatio={aspectRatio}
        minWidth={minWidth}
        minHeight={minHeight}
      />
    </>
  )
}
