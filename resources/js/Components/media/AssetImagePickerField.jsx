/**
 * AssetImagePickerField — Trigger + preview wrapper for AssetImagePicker.
 * Shows preview on main page, opens modal on click.
 * Handles both asset_id (preview from thumbnail) and file (preview from blob).
 * Supports in-place re-cropping — cropped images are uploaded as new assets.
 */
import { useState, useRef, useEffect } from 'react'
import { PhotoIcon, ScissorsIcon } from '@heroicons/react/24/outline'
import AssetImagePicker from './AssetImagePicker'
import ImageCropModal from '../ImageCropModal'

async function uploadFileAsAsset(file, { brandId, contextCategory }) {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content
  const headers = { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }
  let uploadKey, uploadUrl

  const categorySlug = contextCategory === 'icons' ? 'logos' : (contextCategory || 'logos')

  const initRes = await fetch('/app/uploads/initiate-batch', {
    method: 'POST', headers, credentials: 'same-origin',
    body: JSON.stringify({
      files: [{ file_name: file.name, file_size: file.size, mime_type: file.type }],
      ...(brandId ? { brand_id: brandId } : {}),
    }),
  })
  if (!initRes.ok) throw new Error(`Initiate failed: ${initRes.status}`)
  const initData = await initRes.json()
  const result = initData.uploads?.[0]
  const sid = result?.upload_session_id
  uploadKey = `temp/uploads/${sid}/original`
  uploadUrl = result?.upload_url

  if (!uploadUrl) throw new Error('No upload URL returned')

  const putRes = await fetch(uploadUrl, {
    method: 'PUT',
    headers: { 'Content-Type': file.type || 'application/octet-stream' },
    body: file,
  })
  if (!putRes.ok) throw new Error(`Upload failed: ${putRes.status}`)

  const manifestEntry = {
    upload_key: uploadKey,
    expected_size: file.size,
    resolved_filename: file.name,
    category_slug: categorySlug,
  }

  const finalRes = await fetch('/app/assets/upload/finalize', {
    method: 'POST', headers, credentials: 'same-origin',
    body: JSON.stringify({ manifest: [manifestEntry] }),
  })
  if (!finalRes.ok) throw new Error(`Finalize failed: ${finalRes.status}`)
  const finalData = await finalRes.json()
  const assetResult = finalData.results?.[0]
  const assetId = assetResult?.asset_id ?? assetResult?.id
  if (!assetId) throw new Error('No asset ID returned')
  return { asset_id: assetId, thumbnail_url: assetResult?.thumbnail_url ?? assetResult?.final_thumbnail_url ?? null }
}

export default function AssetImagePickerField({
  value = null,
  onChange,
  fetchAssets,
  title = 'Select image',
  defaultCategoryLabel = 'Logos',
  contextCategory = null,
  aspectRatio = null,
  minWidth = 100,
  minHeight = 100,
  placeholder = 'Click or drag to upload',
  helperText = '',
  disabled = false,
  className = '',
  getAssetDownloadUrl = null,
  theme = 'light',
  allowCrop = true,
  brandId = null,
}) {
  const [pickerOpen, setPickerOpen] = useState(false)
  const [cropOpen, setCropOpen] = useState(false)
  const [imageToCrop, setImageToCrop] = useState(null)
  const [uploading, setUploading] = useState(false)
  const mountedRef = useRef(true)
  useEffect(() => () => { mountedRef.current = false }, [])

  const previewUrl =
    typeof value === 'string'
      ? value
      : value?.preview_url ?? null
  const assetId = value?.asset_id ?? null
  const hasValue = !!(previewUrl || assetId || value?.file)

  const isSvg = typeof previewUrl === 'string' && previewUrl.toLowerCase().endsWith('.svg')
  const canCrop = allowCrop && hasValue && previewUrl && !isSvg && !uploading

  const handleSelect = (result) => {
    if (result.asset_id) {
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

  const handleCropClick = (e) => {
    e.stopPropagation()
    if (disabled || !previewUrl || uploading) return
    setImageToCrop(previewUrl)
    setCropOpen(true)
  }

  const handleCropComplete = async (croppedBlob) => {
    const file = new File([croppedBlob], 'cropped.png', { type: 'image/png' })
    const croppedUrl = URL.createObjectURL(file)

    if (imageToCrop && imageToCrop.startsWith('blob:')) {
      URL.revokeObjectURL(imageToCrop)
    }
    setImageToCrop(null)
    setCropOpen(false)

    onChange?.({ preview_url: croppedUrl, _uploading: true })
    setUploading(true)

    try {
      const result = await uploadFileAsAsset(file, { brandId, contextCategory })
      if (!mountedRef.current) return
      onChange?.({ asset_id: result.asset_id, thumbnail_url: result.thumbnail_url, preview_url: croppedUrl })
    } catch (err) {
      console.error('[AssetImagePickerField] Crop upload failed:', err)
      if (!mountedRef.current) return
      onChange?.({ preview_url: croppedUrl, _uploadError: err.message })
    } finally {
      if (mountedRef.current) setUploading(false)
    }
  }

  return (
    <div className={className}>
      <div
        onClick={() => !disabled && !uploading && setPickerOpen(true)}
        className={`relative flex flex-col items-center justify-center rounded-xl border-2 border-dashed transition-all cursor-pointer min-h-[140px] ${
          theme === 'dark'
            ? (hasValue
                ? 'border-white/20 bg-transparent hover:border-white/30'
                : 'border-white/20 bg-transparent hover:border-indigo-400/40')
            : (hasValue
                ? 'border-gray-200/80 bg-gray-50/30 hover:border-gray-300 hover:bg-gray-50/50'
                : 'border-gray-300/80 bg-gray-50/40 hover:border-indigo-300/60 hover:bg-indigo-50/20')
        } ${disabled || uploading ? 'opacity-60 cursor-not-allowed' : ''}`}
      >
        {hasValue ? (
          <div className="w-full p-4">
            <div className="flex items-center justify-between mb-2">
              <p className={`text-xs font-medium ${theme === 'dark' ? 'text-white/70' : 'text-gray-700'}`}>
                {uploading ? 'Uploading cropped image…' : 'Current image'}
              </p>
              {!uploading && (
              <div className="flex items-center gap-3">
                {canCrop && (
                  <button
                    type="button"
                    onClick={handleCropClick}
                    className={`text-xs font-medium flex items-center gap-1 ${theme === 'dark' ? 'text-indigo-400 hover:text-indigo-300' : 'text-indigo-600 hover:text-indigo-800'}`}
                  >
                    <ScissorsIcon className="w-3.5 h-3.5" />
                    Crop
                  </button>
                )}
                <span className={`text-xs font-medium ${theme === 'dark' ? 'text-indigo-400 hover:text-indigo-300' : 'text-indigo-600 hover:text-indigo-800'}`}>
                  Change
                </span>
                <button
                  type="button"
                  onClick={handleRemove}
                  className={`text-xs font-medium ${theme === 'dark' ? 'text-white/50 hover:text-red-400' : 'text-gray-500 hover:text-red-600'}`}
                >
                  Remove
                </button>
              </div>
              )}
            </div>
            <div className="relative">
              <img
                src={previewUrl}
                alt=""
                className={`max-h-32 w-auto max-w-full border rounded-lg p-3 mx-auto object-contain ${
                  theme === 'dark' ? 'border-white/10 bg-white/5' : 'border-gray-200 bg-gray-300'
                }`}
                onError={(e) => {
                  e.target.style.display = 'none'
                }}
              />
              {uploading && (
                <div className="absolute inset-0 flex items-center justify-center rounded-lg bg-white/70">
                  <div className="animate-spin rounded-full h-6 w-6 border-2 border-indigo-600 border-t-transparent" />
                </div>
              )}
            </div>
            <p className={`mt-2 text-xs text-center ${theme === 'dark' ? 'text-white/40' : 'text-gray-500'}`}>
              {uploading ? 'Saving…' : 'Click to replace'}
            </p>
          </div>
        ) : (
          <>
            <PhotoIcon className={`w-12 h-12 mx-auto ${theme === 'dark' ? 'text-white/30' : 'text-gray-400'}`} />
            <p className={`mt-3 text-sm font-medium ${theme === 'dark' ? 'text-white/50' : 'text-gray-600'}`}>{placeholder}</p>
            {helperText && <p className={`mt-1 text-xs ${theme === 'dark' ? 'text-white/30' : 'text-gray-500'}`}>{helperText}</p>}
          </>
        )}
      </div>
      {helperText && !hasValue && <p className={`mt-2 text-xs ${theme === 'dark' ? 'text-white/30' : 'text-gray-500'}`}>{helperText}</p>}

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
        brandId={brandId}
      />

      <ImageCropModal
        open={cropOpen}
        imageSrc={imageToCrop}
        onClose={() => {
          setCropOpen(false)
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
    </div>
  )
}
