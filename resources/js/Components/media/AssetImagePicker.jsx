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
import { useState, useEffect, useRef, useCallback, useMemo } from 'react'
import { usePage } from '@inertiajs/react'
import { XMarkIcon, PhotoIcon, CloudArrowUpIcon, CheckIcon } from '@heroicons/react/24/outline'
import ImageCropModal from '../ImageCropModal'
import { getInlineImagePickerAccept, syncDamFileTypesFromPage } from '../../utils/damFileTypes'
import { parseUploadFinalizeResult, uploadPutContentType } from '../../utils/uploadFinalize'

/**
 * Tile-sized <img> that gracefully falls through a list of candidate URLs on load error.
 *
 * The DAM backend returns several shapes for a given asset (thumbnail_url, final_thumbnail_url,
 * preview_thumbnail_url, original). For SVG logos the rasterized WebP thumbnail occasionally
 * 404s (Imagick missing, stale path, signed URL expiry) — when it does, we want to fall back
 * to `original` (the raw SVG), which is what the user really wants to see anyway. If every
 * candidate fails we show a neutral placeholder rather than the browser's broken-image glyph.
 */
function AssetThumbnail({ asset, className = '', alt = '' }) {
  const isSvgAsset =
    asset?.mime_type === 'image/svg+xml' ||
    asset?.original_filename?.toLowerCase().endsWith('.svg')

  // SVG: prefer the original vector (always resolves, renders crisp at any tile size) and only
  // fall through to raster thumbnails if the original link is missing. Raster: keep the small
  // thumbnail first for bandwidth, then fall back to original if a rasterizer 404s.
  const candidates = (
    isSvgAsset
      ? [asset?.original, asset?.thumbnail_url, asset?.final_thumbnail_url, asset?.preview_thumbnail_url]
      : [asset?.thumbnail_url, asset?.final_thumbnail_url, asset?.preview_thumbnail_url, asset?.original]
  ).filter((u) => typeof u === 'string' && u.length > 0)

  const [idx, setIdx] = useState(0)
  // Reset the walk when the asset identity changes (re-selection, new browse page).
  useEffect(() => {
    setIdx(0)
  }, [asset?.id])

  const src = candidates[idx]
  if (!src) {
    return (
      <div className={`flex items-center justify-center text-xs text-gray-400 ${className}`}>
        —
      </div>
    )
  }
  return (
    <img
      src={src}
      alt={alt}
      className={className}
      onError={() => {
        if (idx < candidates.length - 1) setIdx(idx + 1)
        else setIdx(candidates.length) // exhausted — render the placeholder branch above
      }}
    />
  )
}

const CONTEXT_LABELS = {
  logos: 'Logos',
  icons: 'Icons',
  photography: 'Photography',
  graphics: 'Graphics',
}

export default function AssetImagePicker({
  open,
  onClose,
  fetchAssets,
  fetchDeliverables = null, // optional: when provided with fetchAssets, shows Assets | Deliverables source toggle
  onSelect,
  title = 'Select image',
  defaultCategoryLabel = 'Logos',
  contextCategory = null, // 'logos' | 'icons' | 'photography' | 'graphics' | null
  aspectRatio = null,
  minWidth = 100,
  minHeight = 100,
  acceptFileTypes = null,
  disabled = false,
  singleSelect = true,
  maxSelection = 1,
  initialSelectedIds = [],
  getAssetDownloadUrl = null,
  brandId = null, // required for builder-staged uploads
}) {
  const page = usePage()
  const damFileTypes = page.props.dam_file_types
  useEffect(() => {
    syncDamFileTypesFromPage({ props: { dam_file_types: damFileTypes } })
  }, [damFileTypes])
  const defaultImageAccept = useMemo(() => getInlineImagePickerAccept(), [damFileTypes])
  const effectiveAccept = acceptFileTypes ?? defaultImageAccept

  const isMulti = maxSelection > 1
  const skipCrop = isMulti || contextCategory === 'photography' || contextCategory === 'graphics'
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
  const [uploadQueue, setUploadQueue] = useState([]) // multi-file: [{file, previewUrl}]
  const [uploadedAssets, setUploadedAssets] = useState([]) // completed: [{id, thumbnail_url}]
  const [uploadingCount, setUploadingCount] = useState(0)
  const [uploadError, setUploadError] = useState(null)
  const [cropModalOpen, setCropModalOpen] = useState(false)
  const [imageToCrop, setImageToCrop] = useState(null)
  // mode: 'context' | 'browse' | 'upload'
  const [mode, setMode] = useState('context')
  // When in browse mode: filter by category slug (null = all)
  const [browseCategoryFilter, setBrowseCategoryFilter] = useState(null)
  // Upload destination: category slug (e.g. 'photography') or '' for reference-only
  const [uploadCategorySlug, setUploadCategorySlug] = useState(() => contextCategory || '')

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
    setUploadQueue([])
    setUploadedAssets([])
    setUploadingCount(0)
    setUploadError(null)
    setBrowseCategoryFilter(null)
    setNextPageUrl(null)
    setUploadCategorySlug(contextCategory || '')
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
        if (Array.isArray(cats) && cats.length > 0) {
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

  const acceptFileWithoutCrop = (file) => {
    const previewUrl = URL.createObjectURL(file)
    if (isMulti) {
      setUploadQueue((prev) => [...prev, { file, previewUrl }])
    } else {
      setUploadedFilePreviewUrl((prev) => {
        if (prev && prev.startsWith('blob:')) URL.revokeObjectURL(prev)
        return previewUrl
      })
      setUploadedFile(file)
      setSelectedAssetId(null)
    }
  }

  const handleFileChange = (e) => {
    const files = Array.from(e.target.files || [])
    if (!files.length) return
    for (const file of files) {
      if (skipCrop || file.type === 'image/svg+xml') {
        acceptFileWithoutCrop(file)
      } else {
        setImageToCrop(URL.createObjectURL(file))
        setCropModalOpen(true)
      }
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

  const uploadOneFile = async (file, previewUrl) => {
    const effectiveSlug = uploadCategorySlug || ''
    const isReferenceOnly = !effectiveSlug
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    let uploadKey, uploadUrl

    if (isReferenceOnly && brandId) {
      const initRes = await fetch('/app/uploads/initiate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          file_name: file.name,
          file_size: file.size,
          mime_type: file.type || (file.name?.toLowerCase().endsWith('.svg') ? 'image/svg+xml' : null),
          brand_id: brandId,
          builder_staged: true,
          builder_context: 'visual_reference',
        }),
      })
      if (!initRes.ok) throw new Error(`Initiate failed: ${initRes.status}`)
      const initData = await initRes.json()
      const sid = initData.upload_session_id
      uploadKey = initData.upload_key ?? (sid ? `temp/uploads/${sid}/original` : null)
      uploadUrl = initData.upload_url
    } else {
      const categorySlug = effectiveSlug === 'icons' ? 'logos' : effectiveSlug
      const cat = categories.find((c) => (c.slug || c.name?.toLowerCase()) === categorySlug)
      const categoryId = cat?.id ?? null
      const initRes = await fetch('/app/uploads/initiate-batch', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          files: [
            {
              file_name: file.name,
              file_size: file.size,
              mime_type: file.type || (file.name?.toLowerCase().endsWith('.svg') ? 'image/svg+xml' : null),
            },
          ],
          category_id: categoryId,
        }),
      })
      if (!initRes.ok) throw new Error(`Initiate failed: ${initRes.status}`)
      const initData = await initRes.json()
      const result = initData.uploads?.[0]
      uploadKey = { key: `temp/uploads/${result?.upload_session_id}/original`, categoryId, categorySlug }
      uploadUrl = result?.upload_url
    }

    if (!uploadUrl) throw new Error('No upload URL returned')
    const putRes = await fetch(uploadUrl, { method: 'PUT', headers: { 'Content-Type': uploadPutContentType(file) }, body: file })
    if (!putRes.ok) throw new Error(`Upload failed: ${putRes.status}`)

    const manifestEntry = { upload_key: typeof uploadKey === 'object' ? uploadKey.key : uploadKey, expected_size: file.size, resolved_filename: file.name }
    if (typeof uploadKey === 'object' && uploadKey.categoryId) manifestEntry.category_id = uploadKey.categoryId
    if (typeof uploadKey === 'object' && uploadKey.categorySlug) manifestEntry.category_slug = uploadKey.categorySlug

    const finalRes = await fetch('/app/assets/upload/finalize', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ manifest: [manifestEntry] }),
    })
    if (!finalRes.ok) throw new Error(`Finalize failed: ${finalRes.status}`)
    const finalData = await finalRes.json()
    const parsed = parseUploadFinalizeResult(finalData)
    return {
      id: parsed.asset_id,
      thumbnail_url: parsed.thumbnail_url ?? parsed.final_thumbnail_url ?? previewUrl ?? null,
    }
  }

  const handleUploadAll = async () => {
    if (uploadQueue.length === 0) return
    setLoading(true)
    setUploadError(null)
    setUploadingCount(uploadQueue.length)
    const results = []
    const errors = []
    for (const item of uploadQueue) {
      try {
        const result = await uploadOneFile(item.file, item.previewUrl)
        results.push(result)
        setUploadedAssets((prev) => [...prev, result])
      } catch (err) {
        console.error('[AssetImagePicker] Upload failed for', item.file.name, err)
        errors.push(`${item.file.name}: ${err.message}`)
      }
      setUploadingCount((c) => Math.max(0, c - 1))
    }
    setUploadQueue([])
    setLoading(false)
    if (errors.length > 0) {
      setUploadError(`Upload failed for ${errors.length} file(s): ${errors.join('; ')}`)
    }
    return results
  }

  const handleConfirm = async () => {
    // Multi-select: upload queued files first, then merge with browsed selections
    if (isMulti) {
      let newlyUploaded = [...uploadedAssets]
      if (uploadQueue.length > 0) {
        const uploaded = await handleUploadAll()
        newlyUploaded = [...newlyUploaded, ...(uploaded || [])]
      }
      const browsedAssets = selectedAssetIds
        .map((id) => assets.find((a) => a.id == id || String(a?.id) === String(id)))
        .filter(Boolean)
      const allAssets = [...browsedAssets, ...newlyUploaded]
      const allIds = allAssets.map((a) => a.id).filter(Boolean)
      if (allIds.length > 0) {
        onSelect?.({ asset_ids: allIds, assets: allAssets })
      }
      onClose()
      return
    }
    if (!isMulti && selectedAssetId) {
      const asset = assets.find((a) => a.id === selectedAssetId)
      const isSvgPick =
        asset?.mime_type === 'image/svg+xml' ||
        asset?.original_filename?.toLowerCase().endsWith('.svg')
      // For SVGs, the vector original is the canonical preview — raster thumbnails routinely 404
      // when Imagick/rsvg isn't available or the generated file was pruned. Prefer original.
      const thumb = isSvgPick
        ? (asset?.original ?? asset?.thumbnail_url ?? asset?.final_thumbnail_url ?? asset?.preview_thumbnail_url ?? null)
        : (asset?.thumbnail_url ?? asset?.final_thumbnail_url ?? asset?.preview_thumbnail_url ?? asset?.original ?? null)
      if (getAssetDownloadUrl) {
        try {
          const url = getAssetDownloadUrl(selectedAssetId)
          const res = await fetch(url, { credentials: 'same-origin' })
          const blob = await res.blob()
          const previewUrl = URL.createObjectURL(blob)
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
      try {
        setLoading(true)
        const immediatePreview = uploadedFilePreviewUrl || URL.createObjectURL(uploadedFile)
        const result = await uploadOneFile(uploadedFile, immediatePreview)
        onSelect?.({ asset_id: result.id, thumbnail_url: result.thumbnail_url, preview_url: immediatePreview })
        onClose()
        return
      } catch (err) {
        console.error('[AssetImagePicker] Upload failed:', err)
        setUploadError(`Upload failed: ${err.message}`)
      } finally {
        setLoading(false)
      }
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

  const canConfirm = (isMulti && (selectedAssetIds.length > 0 || uploadQueue.length > 0 || uploadedAssets.length > 0)) || (!isMulti && (selectedAssetId || uploadedFile))

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
                              <AssetThumbnail asset={asset} className="w-full h-full object-contain" />
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
              ) : (uploadedFile && !isMulti) ? (
                <div className="flex flex-col items-center justify-center rounded-xl border-2 border-indigo-200 bg-indigo-50/30 px-8 py-12 relative w-full">
                  {loading ? (
                    <div className="absolute inset-0 flex flex-col items-center justify-center rounded-xl bg-white/90 z-10">
                      <div className="animate-spin rounded-full h-10 w-10 border-2 border-indigo-600 border-t-transparent" />
                      <p className="mt-3 text-sm font-medium text-gray-700">Uploading…</p>
                    </div>
                  ) : null}
                  {uploadedFilePreviewUrl ? (
                    <img src={uploadedFilePreviewUrl} alt="" className="max-h-48 max-w-full object-contain rounded-lg bg-gray-300 border border-gray-200" />
                  ) : (
                    <div className="max-h-48 w-48 rounded-lg bg-gray-300 border border-gray-200 flex items-center justify-center text-gray-500 text-sm">Preview</div>
                  )}
                  <p className="mt-4 text-sm font-medium text-gray-700">{uploadedFile.name}</p>
                  <div className="mt-4 w-full max-w-xs">
                    <label className="block text-xs font-medium text-gray-500 mb-1">Save to</label>
                    <select value={uploadCategorySlug} onChange={(e) => setUploadCategorySlug(e.target.value)} disabled={loading} className="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:ring-indigo-600 focus:border-indigo-600">
                      {contextCategory && <option value={contextCategory}>{CONTEXT_LABELS[contextCategory] ?? contextCategory} (Asset Library)</option>}
                      {!contextCategory && <><option value="photography">Photography (Asset Library)</option><option value="graphics">Graphics (Asset Library)</option><option value="logos">Logos (Asset Library)</option></>}
                      {contextCategory && contextCategory !== 'photography' && <option value="photography">Photography (Asset Library)</option>}
                      {contextCategory && contextCategory !== 'graphics' && <option value="graphics">Graphics (Asset Library)</option>}
                      {contextCategory && contextCategory !== 'logos' && <option value="logos">Logos (Asset Library)</option>}
                      <option value="">Reference only (don&apos;t add to library)</option>
                    </select>
                    <p className="mt-1 text-[11px] text-gray-400">{uploadCategorySlug ? 'Will be published to your asset library.' : 'Stays as a reference \u2014 won\u2019t appear in the asset library.'}</p>
                  </div>
                  <button type="button" onClick={() => { setUploadedFilePreviewUrl((prev) => { if (prev && prev.startsWith('blob:')) URL.revokeObjectURL(prev); return null }); setUploadedFile(null) }} disabled={loading} className="mt-4 text-sm font-medium text-indigo-600 hover:text-indigo-800 disabled:opacity-50 disabled:pointer-events-none">
                    Choose different image
                  </button>
                </div>
              ) : (
                <div className="w-full space-y-4">
                  {/* Queued files preview (multi-select) */}
                  {isMulti && (uploadQueue.length > 0 || uploadedAssets.length > 0) && (
                    <div>
                      <div className="flex items-center justify-between mb-2">
                        <span className="text-xs font-medium text-gray-500">{uploadQueue.length} queued{uploadedAssets.length > 0 ? `, ${uploadedAssets.length} uploaded` : ''}</span>
                        {uploadQueue.length > 0 && (
                          <button type="button" onClick={() => { uploadQueue.forEach((q) => { if (q.previewUrl?.startsWith('blob:')) URL.revokeObjectURL(q.previewUrl) }); setUploadQueue([]) }} className="text-xs text-red-500 hover:text-red-700">Clear queue</button>
                        )}
                      </div>
                      <div className="grid grid-cols-6 gap-2">
                        {uploadedAssets.map((a, i) => (
                          <div key={`done-${a.id || i}`} className="relative rounded-lg border-2 border-green-300 bg-green-50 aspect-square overflow-hidden">
                            {a.thumbnail_url && <img src={a.thumbnail_url} alt="" className="w-full h-full object-cover" />}
                            <div className="absolute top-1 right-1 rounded-full bg-green-600 p-0.5"><CheckIcon className="w-3 h-3 text-white" /></div>
                          </div>
                        ))}
                        {uploadQueue.map((q, i) => (
                          <div key={`q-${i}`} className="relative rounded-lg border-2 border-gray-200 bg-gray-50 aspect-square overflow-hidden group">
                            {q.previewUrl && <img src={q.previewUrl} alt="" className="w-full h-full object-cover" />}
                            <button type="button" onClick={() => { if (q.previewUrl?.startsWith('blob:')) URL.revokeObjectURL(q.previewUrl); setUploadQueue((prev) => prev.filter((_, j) => j !== i)) }} className="absolute top-1 right-1 p-0.5 rounded-full bg-gray-800/70 text-white hover:bg-red-600 opacity-0 group-hover:opacity-100 transition-opacity">
                              <XMarkIcon className="w-3 h-3" />
                            </button>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                  {/* Drop zone */}
                  <div
                    className="relative flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-300 bg-gray-50/50 px-8 py-12 transition-colors hover:border-gray-400 hover:bg-gray-50"
                    onDragOver={(e) => { e.preventDefault(); e.currentTarget.classList.add('border-indigo-400', 'bg-indigo-50/30') }}
                    onDragLeave={(e) => { e.currentTarget.classList.remove('border-indigo-400', 'bg-indigo-50/30') }}
                    onDrop={(e) => {
                      e.preventDefault()
                      e.currentTarget.classList.remove('border-indigo-400', 'bg-indigo-50/30')
                      const files = Array.from(e.dataTransfer.files || []).filter((f) => f.type.startsWith('image/'))
                      for (const file of files) {
                        if (skipCrop || file.type === 'image/svg+xml') {
                          acceptFileWithoutCrop(file)
                        } else {
                          setImageToCrop(URL.createObjectURL(file))
                          setCropModalOpen(true)
                        }
                      }
                    }}
                  >
                    <input
                      type="file"
                      accept={effectiveAccept}
                      multiple={isMulti}
                      onChange={handleFileChange}
                      className="absolute inset-0 w-full h-full cursor-pointer opacity-0"
                    />
                    <CloudArrowUpIcon className="w-12 h-12 text-gray-400 mx-auto" />
                    <p className="mt-3 text-sm font-medium text-gray-600">{isMulti ? 'Click or drag to upload (multiple)' : 'Click or drag to upload'}</p>
                    <p className="mt-1 text-xs text-gray-500">PNG, WebP, SVG, JPEG</p>
                  </div>
                  {/* Category selector for upload */}
                  {(uploadQueue.length > 0 || uploadedFile) && (
                    <div className="max-w-xs">
                      <label className="block text-xs font-medium text-gray-500 mb-1">Save to</label>
                      <select value={uploadCategorySlug} onChange={(e) => setUploadCategorySlug(e.target.value)} disabled={loading} className="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:ring-indigo-600 focus:border-indigo-600">
                        {contextCategory && <option value={contextCategory}>{CONTEXT_LABELS[contextCategory] ?? contextCategory} (Asset Library)</option>}
                        {!contextCategory && <><option value="photography">Photography (Asset Library)</option><option value="graphics">Graphics (Asset Library)</option><option value="logos">Logos (Asset Library)</option></>}
                        {contextCategory && contextCategory !== 'photography' && <option value="photography">Photography (Asset Library)</option>}
                        {contextCategory && contextCategory !== 'graphics' && <option value="graphics">Graphics (Asset Library)</option>}
                        {contextCategory && contextCategory !== 'logos' && <option value="logos">Logos (Asset Library)</option>}
                        <option value="">Reference only (don&apos;t add to library)</option>
                      </select>
                      <p className="mt-1 text-[11px] text-gray-400">{uploadCategorySlug ? 'Will be published to your asset library.' : 'Stays as a reference \u2014 won\u2019t appear in the asset library.'}</p>
                    </div>
                  )}
                </div>
              )}
            </div>

            {uploadError && (
              <div className="mx-5 mb-2 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                {uploadError}
              </div>
            )}
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
                {loading && uploadingCount > 0 ? `Uploading (${uploadingCount})…` : uploadQueue.length > 0 ? `Upload & Select (${uploadQueue.length})` : 'Select'}
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
