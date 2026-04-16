/**
 * Unified asset management modal — metadata, AI review, versions, lifecycle.
 * Reuses existing building blocks (no duplicate field widgets).
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import { usePage, router } from '@inertiajs/react'
import { CloudArrowUpIcon, PencilSquareIcon, XMarkIcon } from '@heroicons/react/24/outline'
import { usePermission } from '../hooks/usePermission'
import AssetMetadataDisplay from './AssetMetadataDisplay'
import AssetTagManager from './AssetTagManager'
import MetadataCandidateReview from './MetadataCandidateReview'
import AiTagSuggestionsInline from './AiTagSuggestionsInline'
import CollectionSelector from './Collections/CollectionSelector'
import ReplaceFileModal from './ReplaceFileModal'
import ConfirmDialog from './ConfirmDialog'
import ThumbnailPreview from './ThumbnailPreview'
import { filterActiveCategories } from '../utils/categoryUtils'
import { getAssetCategoryId } from '../utils/assetUtils'
import { getThumbnailUrl, getThumbnailVersion } from '../utils/thumbnailUtils'
import { getThumbnailModesStatus, modeHasRenderableThumbnails } from '../utils/thumbnailModes'

function SectionTitle({ children, className = '' }) {
    return <div className={`text-sm font-semibold text-gray-900 ${className}`.trim()}>{children}</div>
}

function sortCollectionIdsKey(ids) {
    return [...(ids || [])]
        .map((id) => Number(id))
        .filter((n) => !Number.isNaN(n))
        .sort((a, b) => a - b)
        .join(',')
}

/** Technical file details (read-only). */
function WorkspaceAssetDetailsContent({ asset }) {
    return (
        <div className="w-full max-w-xl px-4 text-sm">
            <dl className="grid grid-cols-2 gap-x-6 gap-y-2">
                <dt className="text-gray-500">File name</dt>
                <dd className="text-right font-medium text-gray-900 truncate" title={asset?.original_filename}>
                    {asset?.original_filename || '—'}
                </dd>
                <dt className="text-gray-500">MIME type</dt>
                <dd className="text-right text-gray-900">{asset?.mime_type || '—'}</dd>
                <dt className="text-gray-500">Size</dt>
                <dd className="text-right text-gray-900">
                    {asset?.file_size != null ? `${(asset.file_size / (1024 * 1024)).toFixed(2)} MB` : '—'}
                </dd>
                <dt className="text-gray-500">Asset ID</dt>
                <dd className="text-right font-mono text-xs text-gray-700">{asset?.id ?? '—'}</dd>
                <dt className="text-gray-500">Updated</dt>
                <dd className="text-right text-gray-900">
                    {asset?.updated_at
                        ? (() => {
                              try {
                                  return new Date(asset.updated_at).toLocaleString()
                              } catch {
                                  return '—'
                              }
                          })()
                        : '—'}
                </dd>
            </dl>
        </div>
    )
}

function manageModalOriginalFileUrl(asset) {
    return asset?.download_url || (asset?.id ? `/app/assets/${asset.id}/download` : null)
}

/**
 * Enhanced / presentation chips: only when that mode finished and we have URLs (not processing/failed).
 * Empty status + URLs is treated as ready (legacy payloads).
 */
function isPipelineModeGeneratedForManageModal(asset, mode) {
    if (!asset || !modeHasRenderableThumbnails(asset, mode)) {
        return false
    }
    const st = String(getThumbnailModesStatus(asset)?.[mode] ?? '').toLowerCase()
    if (st === 'processing' || st === 'failed' || st === 'skipped' || st === 'pending') {
        return false
    }
    return st === 'complete' || st === ''
}

/** Source file chip: video, PDF, audio, etc. — not redundant static rasters (JPG/PNG/WebP…). */
function shouldShowSourceFileChip(asset) {
    const mime = (asset?.mime_type || '').toLowerCase()
    if (mime.startsWith('image/')) {
        return false
    }
    return true
}

function renderSourceFilePreview(asset, originalFileUrl, brandPrimary) {
    const mime = (asset?.mime_type || '').toLowerCase()
    const isSvg =
        mime === 'image/svg+xml' ||
        (asset?.original_filename || '').toLowerCase().endsWith('.svg') ||
        asset?.file_extension === 'svg'
    const isImage = mime.startsWith('image/')
    const isVideo = mime.startsWith('video/')
    const isPdf =
        mime.includes('pdf') || (asset?.original_filename || '').toLowerCase().endsWith('.pdf')

    if (!originalFileUrl) {
        return <p className="text-sm text-gray-500">Source file URL not available.</p>
    }
    if (isVideo) {
        return (
            <video
                src={originalFileUrl}
                controls
                playsInline
                preload="metadata"
                className="max-h-[min(48vh,480px)] w-full max-w-full object-contain"
            />
        )
    }
    if (isImage || isSvg) {
        return <img src={originalFileUrl} alt="" className="max-h-full max-w-full object-contain" />
    }
    if (isPdf) {
        return (
            <div className="flex flex-col items-center gap-3 px-4 py-6 text-center">
                <p className="text-sm text-gray-600">Open the PDF in a new tab to view the full source file.</p>
                <a
                    href={originalFileUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-sm font-medium underline"
                    style={{ color: brandPrimary }}
                >
                    Open PDF
                </a>
            </div>
        )
    }
    return (
        <div className="flex flex-col items-center gap-2 px-4 text-center">
            <p className="text-sm text-gray-600">Preview not shown for this file type.</p>
            <a
                href={originalFileUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="text-sm font-medium underline"
                style={{ color: brandPrimary }}
            >
                Open original file
            </a>
        </div>
    )
}

function PreviewVariantChip({ label, selected, onClick, brandPrimary }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-full border px-3 py-1.5 text-xs font-medium transition-colors ${
                selected
                    ? 'border-transparent shadow-sm'
                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50'
            }`}
            style={
                selected
                    ? {
                          backgroundColor: `${brandPrimary}1a`,
                          color: brandPrimary,
                          borderColor: `${brandPrimary}66`,
                      }
                    : undefined
            }
        >
            {label}
        </button>
    )
}

async function parseJsonResponse(res) {
    const text = await res.text()
    if (!res.ok) {
        const err = new Error(`HTTP ${res.status}`)
        try {
            err.data = text ? JSON.parse(text) : {}
        } catch {
            err.data = { message: text?.slice(0, 200) }
        }
        throw err
    }
    if (!text?.trim()) return {}
    try {
        return JSON.parse(text)
    } catch {
        return {}
    }
}

function fieldKey(f) {
    return f?.field_key || f?.key || ''
}

export default function ManageAssetModal({
    asset,
    isOpen,
    onClose,
    onSaved,
    primaryColor: primaryColorProp,
}) {
    const { auth, categories: categoriesProp = [] } = usePage().props
    const { can } = usePermission()
    const brandPrimary = primaryColorProp || auth?.activeBrand?.primary_color || '#6366f1'

    const planAllowsVersions = auth?.plan_allows_versions ?? false
    const tenantRole = (auth?.tenant_role || '').toLowerCase()
    const canRestoreVersion = tenantRole === 'admin' || tenantRole === 'owner'

    const canEditMetadata = can('metadata.edit_post_upload')
    const canPublish = can('asset.publish')
    const canArchive = can('asset.archive')
    const canDeleteAny = can('assets.delete')
    const canDeleteOwn = can('assets.delete_own')
    const assetOwnerId = asset?.user_id ?? asset?.uploaded_by?.id
    const isOwnAsset = assetOwnerId != null && String(assetOwnerId) === String(auth?.user?.id)
    const canDelete = canDeleteAny || (canDeleteOwn && isOwnAsset)

    const categories = useMemo(() => filterActiveCategories(categoriesProp), [categoriesProp])

    const [titleDraft, setTitleDraft] = useState('')
    const [categoryIdDraft, setCategoryIdDraft] = useState(null)
    const [titleFieldMeta, setTitleFieldMeta] = useState(null)
    const [categoryFieldMeta, setCategoryFieldMeta] = useState(null)
    const [metadataSchemaLoading, setMetadataSchemaLoading] = useState(false)

    const [collectionsList, setCollectionsList] = useState([])
    const [collectionsLoading, setCollectionsLoading] = useState(false)
    const [selectedCollectionIds, setSelectedCollectionIds] = useState([])

    const [versions, setVersions] = useState([])
    const [versionsLoading, setVersionsLoading] = useState(false)
    const [showReplaceFileModal, setShowReplaceFileModal] = useState(false)
    const [restoreTarget, setRestoreTarget] = useState(null)
    const [restoreLoading, setRestoreLoading] = useState(false)

    const [saving, setSaving] = useState(false)
    const [publishLoading, setPublishLoading] = useState(false)
    const [archiveLoading, setArchiveLoading] = useState(false)
    const [deleteLoading, setDeleteLoading] = useState(false)
    const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false)

    const [displayKey, setDisplayKey] = useState(0)
    /** Top bar: visual preview vs file details */
    const [mainTab, setMainTab] = useState('preview')
    /**
     * Under-preview view: default grid thumbnail, pipeline modes, source file, or hover MP4.
     * @type {'default'|'original'|'enhanced'|'presentation'|'source'|'quick'}
     */
    const [previewVariant, setPreviewVariant] = useState('default')
    const [dirtyBaseline, setDirtyBaseline] = useState(null)

    const isVirtualGoogleFont = Boolean(asset?.is_virtual_google_font)

    const thumbnailVersion = useMemo(
        () => `${getThumbnailVersion(asset)}-${displayKey}`,
        [asset, displayKey],
    )

    const resetLocalState = useCallback(() => {
        if (!asset) return
        setTitleDraft(asset.title || asset.original_filename || '')
        const cid = getAssetCategoryId(asset)
        setCategoryIdDraft(cid != null ? String(cid) : '')
        setTitleFieldMeta(null)
        setCategoryFieldMeta(null)
    }, [asset])

    useEffect(() => {
        if (!isOpen || !asset?.id) return
        resetLocalState()
        setMainTab('preview')
        setPreviewVariant('default')
        setDirtyBaseline(null)
    }, [isOpen, asset?.id, resetLocalState])

    useEffect(() => {
        if (!isOpen || !asset?.id || dirtyBaseline !== null) return
        if (metadataSchemaLoading || collectionsLoading) return
        setDirtyBaseline({
            title: (asset.title || asset.original_filename || '').trim(),
            category: String(categoryIdDraft === '' || categoryIdDraft == null ? '' : categoryIdDraft),
            collectionsKey: sortCollectionIdsKey(selectedCollectionIds),
        })
    }, [
        isOpen,
        asset?.id,
        asset?.title,
        asset?.original_filename,
        metadataSchemaLoading,
        collectionsLoading,
        dirtyBaseline,
        categoryIdDraft,
        selectedCollectionIds,
    ])

    const isDirty =
        Boolean(dirtyBaseline) &&
        (titleDraft.trim() !== dirtyBaseline.title ||
            String(categoryIdDraft === '' || categoryIdDraft == null ? '' : categoryIdDraft) !==
                dirtyBaseline.category ||
            sortCollectionIdsKey(selectedCollectionIds) !== dirtyBaseline.collectionsKey)

    useEffect(() => {
        if (!isOpen || !asset?.id || isVirtualGoogleFont) return
        setMetadataSchemaLoading(true)
        fetch(`/app/assets/${asset.id}/metadata/editable`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            credentials: 'same-origin',
        })
            .then((res) => parseJsonResponse(res))
            .then((data) => {
                const fields = data.fields || []
                const titleF = fields.find((f) => {
                    const k = fieldKey(f)
                    return k === 'title' || k === 'asset_title'
                })
                const catF = fields.find((f) => {
                    const k = fieldKey(f)
                    return k === 'category_id' || k === 'category'
                })
                setTitleFieldMeta(titleF || null)
                setCategoryFieldMeta(catF || null)
            })
            .catch(() => {
                setTitleFieldMeta(null)
                setCategoryFieldMeta(null)
            })
            .finally(() => setMetadataSchemaLoading(false))
    }, [isOpen, asset?.id, isVirtualGoogleFont, displayKey])

    const fetchCollections = useCallback(() => {
        if (!asset?.id) return
        setCollectionsLoading(true)
        window.axios
            .get(`/app/assets/${asset.id}/collections`, { headers: { Accept: 'application/json' } })
            .then((res) => {
                const cols = (res.data?.collections ?? []).filter(Boolean)
                setSelectedCollectionIds(cols.map((c) => c.id))
            })
            .catch(() => setSelectedCollectionIds([]))
            .finally(() => setCollectionsLoading(false))
    }, [asset?.id])

    useEffect(() => {
        if (!isOpen || !asset?.id) return
        fetchCollections()
    }, [isOpen, asset?.id, fetchCollections, displayKey])

    useEffect(() => {
        if (!isOpen) return
        setCollectionsLoading(true)
        fetch('/app/collections/list', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((data) => setCollectionsList((data?.collections ?? []).filter(Boolean)))
            .catch(() => setCollectionsList([]))
            .finally(() => {})
    }, [isOpen])

    const fetchVersions = useCallback(() => {
        if (!asset?.id || !planAllowsVersions) return
        setVersionsLoading(true)
        window.axios
            .get(`/app/assets/${asset.id}/versions`, { headers: { Accept: 'application/json' } })
            .then((res) => {
                const data = res.data
                const list = Array.isArray(data) ? data : data?.data ?? []
                setVersions(Array.isArray(list) ? list : [])
            })
            .catch(() => setVersions([]))
            .finally(() => setVersionsLoading(false))
    }, [asset?.id, planAllowsVersions])

    useEffect(() => {
        if (!isOpen || !planAllowsVersions) return
        fetchVersions()
    }, [isOpen, planAllowsVersions, fetchVersions, displayKey])

    useEffect(() => {
        if (!isOpen) return
        const onEsc = (e) => {
            if (e.key === 'Escape') onClose()
        }
        document.addEventListener('keydown', onEsc)
        return () => document.removeEventListener('keydown', onEsc)
    }, [isOpen, onClose])

    useEffect(() => {
        if (isOpen) {
            document.body.style.overflow = 'hidden'
        } else {
            document.body.style.overflow = ''
        }
        return () => {
            document.body.style.overflow = ''
        }
    }, [isOpen])

    const persistMetadataField = async (metadataFieldId, value) => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content
        const res = await fetch(`/app/assets/${asset.id}/metadata/edit`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ metadata_field_id: Number(metadataFieldId), value }),
        })
        if (!res.ok) {
            const data = await res.json().catch(() => ({}))
            throw new Error(data.message || `Save failed (${res.status})`)
        }
    }

    const handleSave = async () => {
        if (!asset?.id || saving || !canEditMetadata) return
        setSaving(true)
        try {
            const tasks = []
            const titleTrim = titleDraft.trim()
            if (titleFieldMeta?.metadata_field_id && titleTrim !== (asset.title || '').trim()) {
                tasks.push(persistMetadataField(titleFieldMeta.metadata_field_id, titleTrim))
            }
            if (categoryFieldMeta?.metadata_field_id) {
                const nextCat =
                    categoryIdDraft === '' || categoryIdDraft == null ? null : Number(categoryIdDraft)
                const prev = getAssetCategoryId(asset)
                const prevN = prev != null ? Number(prev) : null
                const nextN = nextCat == null || Number.isNaN(nextCat) ? null : nextCat
                if (nextN !== prevN) {
                    tasks.push(persistMetadataField(categoryFieldMeta.metadata_field_id, nextN))
                }
            }
            await Promise.all(tasks)

            await window.axios.put(`/app/assets/${asset.id}/collections`, {
                collection_ids: selectedCollectionIds,
            })

            if (typeof window !== 'undefined' && window.toast) {
                window.toast('Asset updated', 'success')
            }
            setDisplayKey((k) => k + 1)
            onSaved?.()
            router.reload({ preserveState: true, preserveScroll: true })
            onClose()
        } catch (e) {
            const msg = e?.response?.data?.message || e.message || 'Failed to save'
            if (typeof window !== 'undefined' && window.toast) {
                window.toast(msg, 'error')
            }
        } finally {
            setSaving(false)
        }
    }

    const handlePublishToggle = async () => {
        if (!asset?.id || publishLoading || !canPublish) return
        setPublishLoading(true)
        try {
            const pub = asset.is_published !== false
            if (pub) {
                await window.axios.post(`/app/assets/${asset.id}/unpublish`)
            } else {
                await window.axios.post(`/app/assets/${asset.id}/publish`)
            }
            if (typeof window !== 'undefined' && window.toast) {
                window.toast(pub ? 'Unpublished' : 'Published', 'success')
            }
            onSaved?.()
            router.reload({ preserveState: true, preserveScroll: true })
        } catch (e) {
            const msg = e?.response?.data?.message || e.message || 'Failed to update'
            if (typeof window !== 'undefined' && window.toast) {
                window.toast(msg, 'error')
            }
        } finally {
            setPublishLoading(false)
        }
    }

    const handleArchive = async () => {
        if (!asset?.id || archiveLoading || !canArchive) return
        setArchiveLoading(true)
        try {
            const endpoint = asset.archived_at ? 'restore' : 'archive'
            await window.axios.post(`/app/assets/${asset.id}/${endpoint}`)
            if (typeof window !== 'undefined' && window.toast) {
                window.toast(asset.archived_at ? 'Restored' : 'Archived', 'success')
            }
            onSaved?.()
            router.reload({ preserveState: true, preserveScroll: true })
            onClose()
        } catch (e) {
            const msg = e?.response?.data?.message || e.message || 'Failed'
            if (typeof window !== 'undefined' && window.toast) {
                window.toast(msg, 'error')
            }
        } finally {
            setArchiveLoading(false)
        }
    }

    const handleDelete = async () => {
        if (!asset?.id || deleteLoading || !canDelete) return
        setDeleteLoading(true)
        try {
            await window.axios.delete(`/app/assets/${asset.id}`)
            if (typeof window !== 'undefined' && window.toast) {
                window.toast('Asset deleted', 'success')
            }
            onSaved?.()
            router.reload({ preserveState: true, preserveScroll: true })
            setDeleteConfirmOpen(false)
            onClose()
        } catch (e) {
            const msg = e?.response?.data?.message || e.message || 'Failed to delete'
            if (typeof window !== 'undefined' && window.toast) {
                window.toast(msg, 'error')
            }
        } finally {
            setDeleteLoading(false)
        }
    }

    const handleRestoreVersion = async () => {
        if (!restoreTarget?.id || !asset?.id || restoreLoading) return
        setRestoreLoading(true)
        try {
            await window.axios.post(`/app/assets/${asset.id}/versions/${restoreTarget.id}/restore`, {
                preserve_metadata: true,
                rerun_pipeline: false,
            })
            if (typeof window !== 'undefined' && window.toast) {
                window.toast('Version restored', 'success')
            }
            setRestoreTarget(null)
            fetchVersions()
            setDisplayKey((k) => k + 1)
            onSaved?.()
            router.reload({ preserveState: true, preserveScroll: true })
        } catch (e) {
            const msg = e?.response?.data?.message || e.message || 'Restore failed'
            if (typeof window !== 'undefined' && window.toast) {
                window.toast(msg, 'error')
            }
        } finally {
            setRestoreLoading(false)
        }
    }

    const originalFileUrl = manageModalOriginalFileUrl(asset)
    const mimeLower = (asset?.mime_type || '').toLowerCase()
    const isVideoAsset = mimeLower.startsWith('video/')
    const showEnhanced = asset ? isPipelineModeGeneratedForManageModal(asset, 'enhanced') : false
    const showPresentation = asset ? isPipelineModeGeneratedForManageModal(asset, 'presentation') : false
    const showSource = asset ? shouldShowSourceFileChip(asset) : false
    const showQuickPreview = isVideoAsset && Boolean(asset?.video_preview_url)

    const previewVariantAllowed = useMemo(() => {
        const set = new Set(['default'])
        if (showEnhanced) set.add('enhanced')
        if (showPresentation) set.add('presentation')
        if (showSource) set.add('source')
        if (showQuickPreview) set.add('quick')
        return set
    }, [showEnhanced, showPresentation, showSource, showQuickPreview])

    useEffect(() => {
        if (!previewVariantAllowed.has(previewVariant)) {
            setPreviewVariant('default')
        }
    }, [previewVariantAllowed, previewVariant])

    const forcedModeUrl =
        asset &&
        (previewVariant === 'enhanced' || previewVariant === 'presentation')
            ? getThumbnailUrl(asset, 'large', previewVariant)
            : null

    if (!isOpen || !asset?.id) {
        return null
    }

    const fmtDate = (d) => {
        if (!d) return '—'
        try {
            return new Date(d).toLocaleString()
        } catch {
            return '—'
        }
    }

    const fmtSize = (b) => {
        if (b == null) return '—'
        if (b < 1024) return `${b} B`
        if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`
        return `${(b / (1024 * 1024)).toFixed(1)} MB`
    }

    const mainTabs = [
        { id: 'preview', label: 'Preview' },
        { id: 'details', label: 'Details' },
    ]

    return (
        <div className="fixed inset-0 z-[200] flex items-center justify-center bg-black/40 p-4">
            <button
                type="button"
                className="absolute inset-0"
                aria-label="Close"
                onClick={onClose}
            />
            <div className="relative z-[201] flex h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
                <div className="flex flex-shrink-0 items-center justify-between gap-4 border-b border-gray-200 px-6 py-4">
                    <div
                        className={`group flex min-w-0 flex-1 items-center gap-2 rounded-lg border px-3 py-2 transition-colors ${
                            !canEditMetadata || isVirtualGoogleFont
                                ? 'border-transparent'
                                : 'border border-gray-200 bg-white shadow-sm hover:border-indigo-200 hover:shadow focus-within:border-indigo-400 focus-within:ring-2 focus-within:ring-indigo-100'
                        }`}
                    >
                        {canEditMetadata && !isVirtualGoogleFont && (
                            <PencilSquareIcon
                                className="h-5 w-5 shrink-0 text-gray-400 transition-opacity group-focus-within:text-indigo-500 group-hover:text-gray-500"
                                aria-hidden
                            />
                        )}
                        <input
                            type="text"
                            value={titleDraft}
                            onChange={(e) => setTitleDraft(e.target.value)}
                            disabled={!canEditMetadata || isVirtualGoogleFont}
                            className="min-w-0 flex-1 border-0 bg-transparent text-lg font-semibold text-gray-900 outline-none ring-0 placeholder:text-gray-400 focus:ring-0 disabled:cursor-not-allowed disabled:opacity-60"
                            placeholder="Asset name — click to edit"
                            aria-label="Asset name"
                        />
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-800"
                        aria-label="Close"
                    >
                        <XMarkIcon className="h-5 w-5" />
                    </button>
                </div>

                <div className="flex min-h-0 flex-shrink-0 flex-col border-b border-gray-100">
                    <div className="flex gap-6 px-6 pt-4 text-sm">
                        {mainTabs.map((t) => (
                            <button
                                key={t.id}
                                type="button"
                                onClick={() => setMainTab(t.id)}
                                className={`border-b-2 pb-3 font-medium transition-colors ${
                                    mainTab === t.id
                                        ? 'text-gray-900'
                                        : 'border-transparent text-gray-500 hover:text-gray-700'
                                }`}
                                style={{
                                    borderBottomColor: mainTab === t.id ? brandPrimary : 'transparent',
                                }}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>
                    {mainTab === 'details' ? (
                        <div className="flex min-h-[200px] items-center justify-center bg-gray-50 py-8">
                            <WorkspaceAssetDetailsContent asset={asset} />
                        </div>
                    ) : (
                        <>
                            <div className="flex h-[min(44vh,400px)] min-h-[280px] w-full flex-col overflow-x-hidden overflow-y-auto bg-gray-100 px-4 py-4 pb-8">
                                <div className="flex h-full min-h-0 w-full flex-1 flex-col items-stretch [&_img]:max-h-full [&_img]:w-auto [&_img]:max-w-full [&_img]:object-contain [&_video]:max-h-full [&_video]:w-full [&_video]:max-w-full [&_video]:object-contain">
                                    {previewVariant === 'quick' && showQuickPreview ? (
                                        <video
                                            key={asset.video_preview_url}
                                            src={asset.video_preview_url}
                                            controls
                                            muted
                                            playsInline
                                            preload="metadata"
                                            className="h-full min-h-0 w-full max-w-full flex-1 object-contain"
                                        />
                                    ) : previewVariant === 'source' && showSource ? (
                                        <div className="flex min-h-[240px] flex-1 flex-col items-center justify-center">
                                            {renderSourceFilePreview(asset, originalFileUrl, brandPrimary)}
                                        </div>
                                    ) : forcedModeUrl ? (
                                        <ThumbnailPreview
                                            asset={asset}
                                            thumbnailVersion={thumbnailVersion}
                                            size="lg"
                                            preferLargeForVector
                                            forcedImageUrl={forcedModeUrl}
                                            className="h-full w-full flex-1"
                                            primaryColor={brandPrimary}
                                            forceObjectFit="contain"
                                        />
                                    ) : (
                                        <ThumbnailPreview
                                            asset={asset}
                                            thumbnailVersion={thumbnailVersion}
                                            size="lg"
                                            preferLargeForVector
                                            className="h-full w-full flex-1"
                                            primaryColor={brandPrimary}
                                            forceObjectFit="contain"
                                        />
                                    )}
                                </div>
                            </div>
                            <div className="border-t border-gray-200 bg-gray-50 px-6 py-3">
                                <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                    View
                                </p>
                                <div className="flex flex-wrap items-center gap-2">
                                    <PreviewVariantChip
                                        label="Preview"
                                        selected={previewVariant === 'default'}
                                        onClick={() => setPreviewVariant('default')}
                                        brandPrimary={brandPrimary}
                                    />
                                    {showEnhanced && (
                                        <PreviewVariantChip
                                            label="Enhanced"
                                            selected={previewVariant === 'enhanced'}
                                            onClick={() => setPreviewVariant('enhanced')}
                                            brandPrimary={brandPrimary}
                                        />
                                    )}
                                    {showPresentation && (
                                        <PreviewVariantChip
                                            label="Presentation"
                                            selected={previewVariant === 'presentation'}
                                            onClick={() => setPreviewVariant('presentation')}
                                            brandPrimary={brandPrimary}
                                        />
                                    )}
                                    {showQuickPreview && (
                                        <PreviewVariantChip
                                            label="Quick preview"
                                            selected={previewVariant === 'quick'}
                                            onClick={() => setPreviewVariant('quick')}
                                            brandPrimary={brandPrimary}
                                        />
                                    )}
                                    {showSource && (
                                        <PreviewVariantChip
                                            label={isVideoAsset ? 'Source video' : 'Source file'}
                                            selected={previewVariant === 'source'}
                                            onClick={() => setPreviewVariant('source')}
                                            brandPrimary={brandPrimary}
                                        />
                                    )}
                                </div>
                                <p className="mt-2 text-xs text-gray-500">
                                    <span className="font-medium text-gray-600">Enhanced</span> and{' '}
                                    <span className="font-medium text-gray-600">Presentation</span> appear only after those
                                    renditions finish. <span className="font-medium text-gray-600">Quick preview</span> is the
                                    short hover MP4. Raster images have no source tab — use Details for the file name.
                                </p>
                            </div>
                        </>
                    )}
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                    <div className="space-y-6">
                        {!isVirtualGoogleFont && canEditMetadata && (
                            <>
                                <section className="space-y-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                                    <SectionTitle>Fields</SectionTitle>
                                    {metadataSchemaLoading ? (
                                        <p className="text-sm text-gray-500">Loading…</p>
                                    ) : (
                                        <>
                                            {categoryFieldMeta && (
                                                <div className="flex items-center justify-between gap-3 border-b border-gray-100 py-2">
                                                    <span className="shrink-0 text-sm text-gray-500">Category</span>
                                                    <div className="max-w-[70%] rounded-md border border-dashed border-gray-200 bg-gray-50/80 px-2 py-1 transition-colors hover:border-gray-300 focus-within:border-indigo-300 focus-within:bg-white focus-within:ring-1 focus-within:ring-indigo-200">
                                                        <select
                                                            value={categoryIdDraft ?? ''}
                                                            onChange={(e) =>
                                                                setCategoryIdDraft(
                                                                    e.target.value === '' ? '' : e.target.value,
                                                                )
                                                            }
                                                            className="w-full cursor-pointer border-0 bg-transparent text-right text-sm text-gray-900 outline-none focus:ring-0"
                                                            aria-label="Category"
                                                        >
                                                            <option value="">Uncategorized</option>
                                                            {categories.map((c) => (
                                                                <option key={c.id} value={String(c.id)}>
                                                                    {c.name}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </div>
                                                </div>
                                            )}

                                            <div className="-mx-1">
                                                <AssetMetadataDisplay
                                                    key={`manage-meta-${asset.id}-${displayKey}`}
                                                    assetId={asset.id}
                                                    primaryColor={brandPrimary}
                                                    suppressAnalysisRunningBanner
                                                    workspaceMode
                                                />
                                            </div>

                                            <div className="space-y-2 border-t border-gray-100 pt-3">
                                                <span className="text-sm text-gray-500">Collections</span>
                                                {collectionsLoading ? (
                                                    <span className="text-sm text-gray-400">Loading collections…</span>
                                                ) : (
                                                    <CollectionSelector
                                                        collections={collectionsList}
                                                        selectedIds={selectedCollectionIds}
                                                        onChange={setSelectedCollectionIds}
                                                        placeholder="Select collections…"
                                                    />
                                                )}
                                            </div>
                                        </>
                                    )}
                                </section>

                                <section className="space-y-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                                    <SectionTitle>Tags</SectionTitle>
                                    <AssetTagManager
                                        asset={asset}
                                        showTitle={false}
                                        showInput
                                        compact
                                        inline
                                        primaryColor={brandPrimary}
                                    />
                                </section>

                                <section className="space-y-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                                    <SectionTitle>AI &amp; review</SectionTitle>
                                    <MetadataCandidateReview
                                        assetId={asset.id}
                                        primaryColor={brandPrimary}
                                        uploadedByUserId={asset.user_id}
                                        compactDrawerReview={false}
                                    />
                                    <AiTagSuggestionsInline
                                        key={`manage-ai-tags-${asset.id}-${displayKey}`}
                                        assetId={asset.id}
                                        uploadedByUserId={asset.user_id}
                                        analysisStatus={asset.analysis_status}
                                        primaryColor={brandPrimary}
                                        drawerInsightGroup={false}
                                        unifiedDrawerReview={false}
                                    />
                                </section>
                            </>
                        )}

                        {planAllowsVersions && !isVirtualGoogleFont && (
                            <section className="space-y-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                                <SectionTitle>Versions</SectionTitle>
                                <button
                                    type="button"
                                    onClick={() => setShowReplaceFileModal(true)}
                                    className="flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 bg-gray-50/80 px-4 py-6 text-sm font-medium text-gray-800 shadow-sm transition-all hover:border-gray-400 hover:bg-gray-50"
                                >
                                    <CloudArrowUpIcon className="h-5 w-5 text-gray-500" aria-hidden />
                                    Upload or replace file
                                </button>
                                {versionsLoading ? (
                                    <p className="text-sm text-gray-500">Loading versions…</p>
                                ) : versions.length === 0 ? (
                                    <p className="text-sm text-gray-500">No version history yet.</p>
                                ) : (
                                    <ul className="divide-y divide-gray-100 border border-gray-100 rounded-lg overflow-hidden">
                                        {versions.map((v) => (
                                            <li
                                                key={v.id}
                                                className="flex flex-wrap items-center justify-between gap-2 bg-white px-3 py-2.5 text-sm"
                                            >
                                                <div className="min-w-0">
                                                    <div className="font-medium text-gray-900">
                                                        v{v.version_number}
                                                        {v.mime_type ? (
                                                            <span className="ml-2 text-xs font-normal text-gray-500">
                                                                {v.mime_type}
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                    <div className="text-xs text-gray-400">
                                                        {fmtDate(v.created_at)} · {fmtSize(v.file_size)}
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    {v.is_current ? (
                                                        <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800">
                                                            Current
                                                        </span>
                                                    ) : canRestoreVersion ? (
                                                        <button
                                                            type="button"
                                                            onClick={() => setRestoreTarget(v)}
                                                            disabled={restoreLoading}
                                                            className="text-xs font-medium text-indigo-600 hover:text-indigo-800 disabled:opacity-50"
                                                        >
                                                            Set current
                                                        </button>
                                                    ) : null}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        )}

                        <section className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                            <SectionTitle className="mb-3">Actions</SectionTitle>
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex flex-col gap-3">
                                    {canPublish && !asset.builder_staged && !asset.deleted_at && (
                                        <label className="flex cursor-pointer items-center gap-2 text-sm text-gray-800">
                                            <input
                                                type="checkbox"
                                                className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                checked={asset.is_published !== false}
                                                disabled={publishLoading}
                                                onChange={handlePublishToggle}
                                            />
                                            <span>Published (visible per your workspace rules)</span>
                                        </label>
                                    )}
                                </div>
                                <div className="flex flex-wrap items-center gap-2 sm:justify-end">
                                    {canArchive && !asset.deleted_at && (
                                        <button
                                            type="button"
                                            onClick={handleArchive}
                                            disabled={archiveLoading}
                                            className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-800 shadow-sm transition-all hover:bg-gray-50 disabled:opacity-50"
                                        >
                                            {archiveLoading
                                                ? 'Working…'
                                                : asset.archived_at
                                                  ? 'Restore from archive'
                                                  : 'Archive'}
                                        </button>
                                    )}
                                    {canDelete && !asset.deleted_at && (
                                        <button
                                            type="button"
                                            onClick={() => setDeleteConfirmOpen(true)}
                                            disabled={deleteLoading}
                                            className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-800 shadow-sm transition-all hover:bg-red-100 disabled:opacity-50"
                                        >
                                            Delete
                                        </button>
                                    )}
                                </div>
                            </div>
                        </section>
                    </div>
                </div>

                <div className="flex flex-shrink-0 items-center justify-between gap-4 border-t border-gray-200 bg-white px-6 py-3">
                    <span className="text-xs text-gray-500">{isDirty ? 'Unsaved changes' : '\u00a0'}</span>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={handleSave}
                            disabled={
                                saving ||
                                !canEditMetadata ||
                                isVirtualGoogleFont ||
                                !isDirty
                            }
                            className="rounded-lg px-4 py-2 text-sm font-medium text-white shadow-sm transition-opacity hover:opacity-95 disabled:cursor-not-allowed disabled:opacity-50"
                            style={{ backgroundColor: brandPrimary }}
                        >
                            {saving ? 'Saving…' : 'Save changes'}
                        </button>
                    </div>
                </div>
            </div>

            {showReplaceFileModal && (
                <ReplaceFileModal
                    asset={asset}
                    isOpen={showReplaceFileModal}
                    zIndexClass="z-[220]"
                    onClose={() => setShowReplaceFileModal(false)}
                    onSuccess={() => {
                        setShowReplaceFileModal(false)
                        fetchVersions()
                        setDisplayKey((k) => k + 1)
                        onSaved?.()
                        router.reload({ preserveState: true, preserveScroll: true })
                    }}
                />
            )}

            <ConfirmDialog
                zIndexClass="z-[230]"
                open={Boolean(restoreTarget)}
                onClose={() => !restoreLoading && setRestoreTarget(null)}
                onConfirm={handleRestoreVersion}
                title="Set current version"
                message={
                    restoreTarget
                        ? `Restore version ${restoreTarget.version_number} as the new current file? This creates a new version entry.`
                        : ''
                }
                confirmText="Set current"
                loading={restoreLoading}
                variant="warning"
            />

            <ConfirmDialog
                zIndexClass="z-[230]"
                open={deleteConfirmOpen}
                onClose={() => !deleteLoading && setDeleteConfirmOpen(false)}
                onConfirm={handleDelete}
                title="Delete asset"
                message="This asset will be moved to trash (soft delete). You can undo from the trash view if your organization allows it."
                confirmText="Delete"
                variant="danger"
                loading={deleteLoading}
            />
        </div>
    )
}
