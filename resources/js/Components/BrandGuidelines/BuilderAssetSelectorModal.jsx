/**
 * Asset Selector Modal for Brand Guidelines Builder.
 *
 * Full-featured dark-themed media picker with:
 * - Tabs: Library (browse existing assets) | Upload (drag-drop or click)
 * - Category-aware fetching (e.g. logos category for logo_reference)
 * - Drag-and-drop upload zone
 * - Asset grid with thumbnails and selection
 */
import { useState, useEffect, useCallback, useRef, useMemo } from 'react'
import { Dialog } from '@headlessui/react'
import { XMarkIcon, CloudArrowUpIcon, PhotoIcon } from '@heroicons/react/24/outline'
import { getInlineImagePickerAccept } from '../../utils/damFileTypes'

const CONTEXT_CATEGORIES = {
    logo_reference: 'logos',
    visual_reference: null,
    brand_material: null,
    typography_reference: null,
    guidelines_pdf: null,
}

export default function BuilderAssetSelectorModal({
    open,
    onClose,
    brandId,
    builderContext,
    onSelect,
    title = 'Select from Assets',
    showUpload = true,
    multiSelect = false,
}) {
    const [assets, setAssets] = useState([])
    const [loading, setLoading] = useState(false)
    const [uploadingFiles, setUploadingFiles] = useState([])
    const [tab, setTab] = useState('library')
    const [dragOver, setDragOver] = useState(false)
    const inputRef = useRef(null)
    const dropRef = useRef(null)
    const uploading = uploadingFiles.length > 0

    const acceptTypes = useMemo(() => {
        const images = getInlineImagePickerAccept()
        if (builderContext === 'guidelines_pdf') {
            return `${images},application/pdf,.pdf`
        }
        return images
    }, [builderContext])

    const contextCategory = CONTEXT_CATEGORIES[builderContext] ?? null

    const fetchAssets = useCallback(async () => {
        setLoading(true)
        try {
            const params = new URLSearchParams({ format: 'json' })
            if (contextCategory) {
                params.set('category', contextCategory)
            } else {
                params.set('source', 'reference_materials')
            }
            const res = await fetch(`/app/assets?${params.toString()}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
            const data = await res.json()
            const list = data?.data ?? data?.assets ?? (Array.isArray(data) ? data : [])
            setAssets(Array.isArray(list) ? list : [])
        } catch {
            setAssets([])
        } finally {
            setLoading(false)
        }
    }, [contextCategory])

    useEffect(() => {
        if (open) {
            fetchAssets()
            setTab(assets.length > 0 ? 'library' : 'library')
            setUploadingFiles([])
        }
    }, [open, fetchAssets])

    const handleSelect = (asset) => {
        onSelect?.(asset)
        if (!multiSelect) onClose()
    }

    const handleUploadFile = useCallback(async (file) => {
        if (!file) return
        const fileId = `${file.name}-${Date.now()}`
        setUploadingFiles((prev) => [...prev, { id: fileId, name: file.name, progress: 0 }])

        const csrf = document.querySelector('meta[name="csrf-token"]')?.content
        try {
            const initRes = await fetch('/app/uploads/initiate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    file_name: file.name,
                    file_size: file.size,
                    mime_type: file.type || null,
                    brand_id: brandId,
                    builder_staged: true,
                    builder_context: builderContext,
                }),
            })
            if (!initRes.ok) throw new Error('Initiate failed')
            const initData = await initRes.json()
            const { upload_type, upload_url } = initData

            setUploadingFiles((prev) => prev.map((f) => f.id === fileId ? { ...f, progress: 30 } : f))

            if (upload_type === 'direct' && upload_url) {
                const putRes = await fetch(upload_url, {
                    method: 'PUT',
                    headers: { 'Content-Type': file.type || 'application/octet-stream' },
                    body: file,
                })
                if (!putRes.ok) throw new Error('Upload failed')
            } else {
                throw new Error('Direct upload required')
            }

            setUploadingFiles((prev) => prev.map((f) => f.id === fileId ? { ...f, progress: 70 } : f))

            const finalRes = await fetch('/app/assets/upload/finalize', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    manifest: [{
                        upload_key: initData.upload_key ?? `temp/uploads/${initData.upload_session_id}/original`,
                        expected_size: file.size,
                        resolved_filename: file.name,
                    }],
                }),
            })
            if (!finalRes.ok) throw new Error('Finalize failed')
            const finalData = await finalRes.json()
            const result = finalData.results?.[0]
            const assetId = result?.asset_id ?? result?.id
            if (assetId) {
                const serverThumb = result?.thumbnail_url ?? result?.final_thumbnail_url ?? null
                const blobPreview = file.type?.startsWith('image/') ? URL.createObjectURL(file) : null
                handleSelect({
                    id: assetId,
                    title: file.name,
                    original_filename: file.name,
                    thumbnail_url: serverThumb,
                    preview_url: blobPreview,
                    signed_url: null,
                    _just_uploaded: true,
                })
            }
        } catch (e) {
            console.error('Upload failed:', e)
        } finally {
            setUploadingFiles((prev) => prev.filter((f) => f.id !== fileId))
        }
    }, [brandId, builderContext, handleSelect])

    const handleFileInput = useCallback((e) => {
        const files = e.target.files
        if (!files?.length) return
        Array.from(files).forEach((f) => handleUploadFile(f))
        e.target.value = ''
    }, [handleUploadFile])

    const handleDrop = useCallback((e) => {
        e.preventDefault()
        e.stopPropagation()
        setDragOver(false)
        const files = e.dataTransfer?.files
        if (!files?.length) return
        Array.from(files).forEach((f) => handleUploadFile(f))
    }, [handleUploadFile])

    const handleDragOver = useCallback((e) => {
        e.preventDefault()
        e.stopPropagation()
        setDragOver(true)
    }, [])

    const handleDragLeave = useCallback((e) => {
        e.preventDefault()
        e.stopPropagation()
        setDragOver(false)
    }, [])

    if (!open) return null

    return (
        <Dialog open={open} onClose={onClose} className="relative z-50">
            <div className="fixed inset-0 bg-black/70 backdrop-blur-sm" aria-hidden="true" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <Dialog.Panel className="mx-auto w-full max-w-4xl max-h-[85vh] overflow-hidden rounded-2xl border border-white/15 bg-[#1a1920] shadow-2xl flex flex-col">
                    {/* Header */}
                    <div className="flex items-center justify-between px-5 py-4 border-b border-white/10 flex-shrink-0">
                        <Dialog.Title className="text-lg font-semibold text-white">{title}</Dialog.Title>
                        <button type="button" onClick={onClose} className="text-white/50 hover:text-white p-1.5 rounded-lg hover:bg-white/10 transition-colors">
                            <XMarkIcon className="w-5 h-5" />
                        </button>
                    </div>

                    {/* Tabs */}
                    <div className="flex gap-1 px-5 py-2 border-b border-white/10 flex-shrink-0">
                        <button
                            type="button"
                            onClick={() => setTab('library')}
                            className={`px-4 py-2 text-sm font-medium rounded-lg transition-colors ${
                                tab === 'library'
                                    ? 'bg-white/10 text-white'
                                    : 'text-white/50 hover:text-white/80 hover:bg-white/5'
                            }`}
                        >
                            <span className="flex items-center gap-2">
                                <PhotoIcon className="w-4 h-4" />
                                Library
                            </span>
                        </button>
                        {showUpload && (
                            <button
                                type="button"
                                onClick={() => setTab('upload')}
                                className={`px-4 py-2 text-sm font-medium rounded-lg transition-colors ${
                                    tab === 'upload'
                                        ? 'bg-white/10 text-white'
                                        : 'text-white/50 hover:text-white/80 hover:bg-white/5'
                                }`}
                            >
                                <span className="flex items-center gap-2">
                                    <CloudArrowUpIcon className="w-4 h-4" />
                                    Upload
                                </span>
                            </button>
                        )}
                    </div>

                    {/* Content */}
                    <div className="flex-1 min-h-0 overflow-y-auto">
                        {tab === 'library' && (
                            <div className="p-5">
                                {loading ? (
                                    <div className="flex items-center justify-center py-16">
                                        <div className="flex flex-col items-center gap-3">
                                            <div className="w-8 h-8 border-2 border-white/20 border-t-indigo-400 rounded-full animate-spin" />
                                            <p className="text-white/50 text-sm">Loading assets…</p>
                                        </div>
                                    </div>
                                ) : assets.length > 0 ? (
                                    <div className="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 gap-3">
                                        {assets.map((asset) => {
                                            const thumbSrc = asset.thumbnail_url || asset.final_thumbnail_url || asset.preview_thumbnail_url || asset.original || asset.signed_url
                                            const fallbackSrc = asset.original || asset.signed_url
                                            return (
                                                <button
                                                    key={asset.id}
                                                    type="button"
                                                    onClick={() => handleSelect(asset)}
                                                    className="group rounded-xl border-2 border-white/10 hover:border-indigo-500/60 overflow-hidden transition-all focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white/5 hover:bg-white/10"
                                                >
                                                    <div className="aspect-square bg-[#12111a] flex items-center justify-center overflow-hidden">
                                                        {thumbSrc ? (
                                                            <img
                                                                src={thumbSrc}
                                                                alt=""
                                                                className="w-full h-full object-contain p-2 group-hover:scale-105 transition-transform"
                                                                onError={(e) => {
                                                                    if (fallbackSrc && e.target.src !== fallbackSrc) {
                                                                        e.target.src = fallbackSrc
                                                                    } else {
                                                                        e.target.style.display = 'none'
                                                                        if (e.target.parentElement) {
                                                                            e.target.parentElement.innerHTML = '<svg class="w-10 h-10 text-white/20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" /></svg>'
                                                                        }
                                                                    }
                                                                }}
                                                            />
                                                        ) : (
                                                            <PhotoIcon className="w-10 h-10 text-white/20" />
                                                        )}
                                                    </div>
                                                    <div className="px-2 py-2">
                                                        <p className="text-xs text-white/60 truncate group-hover:text-white/80" title={asset.original_filename || asset.title}>
                                                            {asset.original_filename || asset.title || 'Asset'}
                                                        </p>
                                                    </div>
                                                </button>
                                            )
                                        })}
                                    </div>
                                ) : (
                                    <div className="flex flex-col items-center justify-center py-16 gap-4">
                                        <div className="w-16 h-16 rounded-2xl bg-white/5 flex items-center justify-center">
                                            <PhotoIcon className="w-8 h-8 text-white/20" />
                                        </div>
                                        <div className="text-center">
                                            <p className="text-white/60 text-sm font-medium">No assets found</p>
                                            <p className="text-white/40 text-xs mt-1">Upload a new one or add assets in your Assets library first</p>
                                        </div>
                                        {showUpload && (
                                            <button
                                                type="button"
                                                onClick={() => setTab('upload')}
                                                className="mt-2 px-4 py-2.5 rounded-xl bg-indigo-500/20 border border-indigo-500/40 text-indigo-300 hover:bg-indigo-500/30 text-sm font-medium transition-colors"
                                            >
                                                Upload New Asset
                                            </button>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}

                        {tab === 'upload' && (
                            <div className="p-5">
                                <div
                                    ref={dropRef}
                                    onDrop={handleDrop}
                                    onDragOver={handleDragOver}
                                    onDragLeave={handleDragLeave}
                                    onClick={() => inputRef.current?.click()}
                                    className={`relative rounded-2xl border-2 border-dashed transition-all cursor-pointer py-16 flex flex-col items-center gap-4 ${
                                        dragOver
                                            ? 'border-indigo-400 bg-indigo-500/10'
                                            : 'border-white/20 hover:border-white/40 bg-white/[0.02] hover:bg-white/5'
                                    }`}
                                >
                                    <input
                                        ref={inputRef}
                                        type="file"
                                        className="hidden"
                                        accept={acceptTypes}
                                        multiple={multiSelect}
                                        onChange={handleFileInput}
                                    />
                                    <div className={`w-16 h-16 rounded-2xl flex items-center justify-center transition-colors ${
                                        dragOver ? 'bg-indigo-500/20' : 'bg-white/5'
                                    }`}>
                                        <CloudArrowUpIcon className={`w-8 h-8 ${dragOver ? 'text-indigo-400' : 'text-white/30'}`} />
                                    </div>
                                    <div className="text-center">
                                        <p className="text-white/80 text-sm font-medium">
                                            {dragOver ? 'Drop to upload' : 'Drag and drop files here'}
                                        </p>
                                        <p className="text-white/40 text-xs mt-1">or click to browse</p>
                                    </div>
                                    <p className="text-white/30 text-xs">
                                        {builderContext === 'logo_reference'
                                            ? 'PNG, SVG, or high-resolution JPG recommended'
                                            : builderContext === 'guidelines_pdf'
                                            ? 'PDF, PNG, JPG accepted'
                                            : 'Images accepted'}
                                    </p>
                                </div>

                                {/* Upload progress */}
                                {uploadingFiles.length > 0 && (
                                    <div className="mt-4 space-y-2">
                                        {uploadingFiles.map((f) => (
                                            <div key={f.id} className="flex items-center gap-3 rounded-lg bg-white/5 border border-white/10 px-4 py-3">
                                                <div className="w-5 h-5 border-2 border-white/20 border-t-indigo-400 rounded-full animate-spin flex-shrink-0" />
                                                <span className="text-sm text-white/70 truncate flex-1">{f.name}</span>
                                                <span className="text-xs text-white/40">Uploading…</span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </Dialog.Panel>
            </div>
        </Dialog>
    )
}
