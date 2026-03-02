/**
 * Asset Selector Modal for Brand Guidelines Builder.
 */
import { useState, useEffect, useCallback, useRef } from 'react'
import { Dialog } from '@headlessui/react'
import { XMarkIcon, CloudArrowUpIcon } from '@heroicons/react/24/outline'

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
    const [uploadingCount, setUploadingCount] = useState(0)
    const inputRef = useRef(null)
    const uploading = uploadingCount > 0

    const fetchAssets = useCallback(async () => {
        setLoading(true)
        try {
            const res = await fetch('/app/assets?format=json', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
            const data = await res.json()
            setAssets(data?.assets ?? [])
        } catch {
            setAssets([])
        } finally {
            setLoading(false)
        }
    }, [])

    useEffect(() => {
        if (open) fetchAssets()
    }, [open, fetchAssets])

    const handleSelect = (asset) => {
        onSelect?.(asset)
        if (!multiSelect) onClose()
    }

    const handleUpload = useCallback(async (file) => {
        if (!file) return
        setUploadingCount((c) => c + 1)
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
            const finalRes = await fetch('/app/assets/upload/finalize', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    manifest: [{ upload_key: initData.upload_key ?? `temp/uploads/${initData.upload_session_id}/original`, expected_size: file.size, resolved_filename: file.name }],
                }),
            })
            if (!finalRes.ok) throw new Error('Finalize failed')
            const finalData = await finalRes.json()
            const result = finalData.results?.[0]
            const assetId = result?.asset_id ?? result?.id
            if (assetId) {
                handleSelect({ id: assetId, title: file.name, original_filename: file.name, thumbnail_url: null, signed_url: null })
            }
        } catch (e) {
            console.error('Upload failed:', e)
        } finally {
            setUploadingCount((c) => Math.max(0, c - 1))
        }
    }, [brandId, builderContext])

    if (!open) return null

    return (
        <Dialog open={open} onClose={onClose} className="relative z-50">
            <div className="fixed inset-0 bg-black/60" aria-hidden="true" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <Dialog.Panel className="mx-auto w-full max-w-4xl max-h-[85vh] overflow-hidden rounded-2xl border border-white/20 bg-[#1a1920] shadow-2xl flex flex-col">
                    <div className="flex items-center justify-between px-4 py-3 border-b border-white/10 flex-shrink-0">
                        <Dialog.Title className="text-lg font-semibold text-white">{title}</Dialog.Title>
                        <button type="button" onClick={onClose} className="text-white/60 hover:text-white p-1">
                            <XMarkIcon className="w-5 h-5" />
                        </button>
                    </div>
                    {showUpload && (
                        <div className="px-4 py-2 border-b border-white/10 flex-shrink-0">
                            <input
                                ref={inputRef}
                                type="file"
                                className="hidden"
                                accept="image/*,.pdf"
                                multiple
                                onChange={(e) => {
                                    const files = e.target.files
                                    if (!files?.length) return
                                    Array.from(files).forEach((f) => handleUpload(f))
                                    e.target.value = ''
                                }}
                            />
                            <button
                                type="button"
                                onClick={() => inputRef.current?.click()}
                                disabled={uploading}
                                className="flex items-center gap-2 px-3 py-2 rounded-lg border border-white/20 text-white/80 hover:bg-white/10 text-sm disabled:opacity-50"
                            >
                                <CloudArrowUpIcon className="w-4 h-4" />
                                {uploading ? 'Uploading…' : 'Upload New Asset'}
                            </button>
                        </div>
                    )}
                    <div className="p-4 overflow-y-auto flex-1 min-h-0">
                        {loading ? (
                            <p className="text-white/60 text-sm">Loading assets…</p>
                        ) : (
                            <div className="grid grid-cols-4 sm:grid-cols-5 md:grid-cols-6 gap-3">
                                {assets.map((asset) => (
                                    <button
                                        key={asset.id}
                                        type="button"
                                        onClick={() => handleSelect(asset)}
                                        className="rounded-lg border-2 border-white/20 hover:border-indigo-500/60 overflow-hidden transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    >
                                        <div className="aspect-square bg-white/5">
                                            {(asset.thumbnail_url || asset.signed_url) ? (
                                                <img src={asset.thumbnail_url || asset.signed_url} alt="" className="w-full h-full object-cover" />
                                            ) : (
                                                <div className="w-full h-full flex items-center justify-center text-white/40 text-2xl">◇</div>
                                            )}
                                        </div>
                                        <p className="text-xs text-white/70 truncate px-1 py-1" title={asset.original_filename || asset.title}>
                                            {asset.original_filename || asset.title || 'Asset'}
                                        </p>
                                    </button>
                                ))}
                            </div>
                        )}
                        {!loading && assets.length === 0 && (
                            <p className="text-white/50 text-sm">No assets yet. Upload one above or add assets in the Assets library.</p>
                        )}
                    </div>
                </Dialog.Panel>
            </div>
        </Dialog>
    )
}
