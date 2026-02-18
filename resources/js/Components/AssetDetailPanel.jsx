/**
 * Asset Detail Panel (Asset Control Center)
 *
 * Phase O: Full-height slide-out panel (80% width) with structured sections,
 * permission-aware visibility, and section-based metadata editing.
 *
 * - Sticky header: preview, title, filename, badges, star, actions
 * - Section 1: Overview (read-only)
 * - Section 2: Metadata (grouped; form-based edit per section if metadata.edit_post_upload)
 * - Section 3: File Information (read-only except filename if allowed)
 * - Section 4: Activity (collapsed by default)
 * - Section 5: Versions (stub)
 * - Section 6: Approval Workflow (stub)
 * - Section 7: Download History (admin/manager only)
 *
 * Reusable as full page in admin contexts via prop fullPage.
 */
import { useEffect, useState, useRef, useMemo } from 'react'
import {
    XMarkIcon,
    ChevronDownIcon,
    ArrowPathIcon,
    TrashIcon,
    LockClosedIcon,
    CheckCircleIcon,
    XCircleIcon,
    ArchiveBoxIcon,
    ArrowUturnLeftIcon,
    PlayIcon,
    StarIcon as StarIconOutline,
    PencilIcon,
    ArrowsPointingOutIcon,
    ArrowsPointingInIcon,
} from '@heroicons/react/24/outline'
import { StarIcon as StarIconSolid } from '@heroicons/react/24/solid'
import ThumbnailPreview from './ThumbnailPreview'
import FileTypeIcon from './FileTypeIcon'
import DominantColorsSwatches from './DominantColorsSwatches'
import AssetTagManager from './AssetTagManager'
import AssetTimeline from './AssetTimeline'
import CollapsibleSection from './CollapsibleSection'
import PermissionGate from './PermissionGate'
import StarRating from './StarRating'
import CollectionSelector from './Collections/CollectionSelector'
import { usePermission } from '../hooks/usePermission'
import { router, usePage } from '@inertiajs/react'
import { supportsThumbnail } from '../utils/thumbnailUtils'
import { resolve, isExcludedFromGenericLoop, hasCollectionField, CONTEXT, WIDGET } from '../utils/widgetResolver'

const GROUP_LABELS = {
    classification: 'Classification',
    rights: 'Rights',
    technical: 'Technical',
    internal: 'Internal',
    creative: 'Creative',
    general: 'General',
    legal: 'Rights',
}

function groupLabel(key) {
    return GROUP_LABELS[key] || (key ? key.charAt(0).toUpperCase() + key.slice(1) : 'General')
}

export default function AssetDetailPanel({
    asset,
    isOpen,
    onClose,
    activityEvents: externalActivityEvents = null,
    activityLoading: externalActivityLoading = false,
    onReplaceFile = null,
    onDelete = null,
    primaryColor,
    fullPage = false,
}) {
    const { auth } = usePage().props
    const brandPrimary = primaryColor || auth?.activeBrand?.primary_color || '#6366f1'

    // Permissions
    const { can } = usePermission()
    const canViewAsset = can('asset.view')
    const canEditMetadata = can('metadata.edit_post_upload')
    const canRegenerateAiMetadata = can('assets.ai_metadata.regenerate')
    const canRegenerateThumbnailsAdmin = can('assets.regenerate_thumbnails_admin')
    const canPublish = can('asset.publish')
    const canUnpublish = can('asset.unpublish')
    const canArchive = can('asset.archive')
    const canRestore = can('asset.restore')

    const tenantRole = auth?.tenant_role || null
    const brandRole = auth?.brand_role || null
    const isOwnerOrAdmin = tenantRole === 'owner' || tenantRole === 'admin'
    const canRegenerateAiMetadataForTroubleshooting = canRegenerateAiMetadata || isOwnerOrAdmin
    const isContributor =
        auth?.user?.brand_role === 'contributor' &&
        !['owner', 'admin'].includes(auth?.user?.tenant_role?.toLowerCase() || '')
    const approvalsEnabled = auth?.approval_features?.approvals_enabled
    const contributorBlocked = isContributor && approvalsEnabled

    const canPublishWithFallback = (canPublish || isOwnerOrAdmin) && !contributorBlocked
    const canUnpublishWithFallback = canUnpublish || isOwnerOrAdmin
    const canArchiveWithFallback = (canArchive || isOwnerOrAdmin) && !contributorBlocked
    const canRestoreWithFallback = canRestore || isOwnerOrAdmin

    const showDownloadHistory =
        ['owner', 'admin'].includes((tenantRole || '').toLowerCase()) ||
        ['brand_manager', 'manager', 'admin'].includes((brandRole || '').toLowerCase())

    const [metadata, setMetadata] = useState(null)
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState(null)
    const [assetCollections, setAssetCollections] = useState([])
    const [assetCollectionsLoading, setAssetCollectionsLoading] = useState(false)
    const [dropdownCollections, setDropdownCollections] = useState([])
    const [dropdownCollectionsLoading, setDropdownCollectionsLoading] = useState(false)
    const [syncCollectionsLoading, setSyncCollectionsLoading] = useState(false)
    const [activityEvents, setActivityEvents] = useState([])
    const [activityLoading, setActivityLoading] = useState(false)

    const [showActionsDropdown, setShowActionsDropdown] = useState(false)
    const actionsDropdownRef = useRef(null)
    const [lifecycleError, setLifecycleError] = useState(null)
    const [publishing, setPublishing] = useState(false)
    const [unpublishing, setUnpublishing] = useState(false)
    const [archiving, setArchiving] = useState(false)
    const [restoring, setRestoring] = useState(false)
    const [regeneratingAiMetadata, setRegeneratingAiMetadata] = useState(false)
    const [regeneratingSystemMetadata, setRegeneratingSystemMetadata] = useState(false)
    const [regeneratingAiTagging, setRegeneratingAiTagging] = useState(false)
    const [regeneratingThumbnails, setRegeneratingThumbnails] = useState(false)
    const [regeneratingVideoThumbnail, setRegeneratingVideoThumbnail] = useState(false)
    const [regeneratingVideoPreview, setRegeneratingVideoPreview] = useState(false)
    const [removePreviewLoading, setRemovePreviewLoading] = useState(false)

    const [metadataEditGroup, setMetadataEditGroup] = useState(null)
    const [metadataDirty, setMetadataDirty] = useState({})

    const [isExiting, setIsExiting] = useState(false)
    const [hasEntered, setHasEntered] = useState(false)
    const [previewExpanded, setPreviewExpanded] = useState(false)
    const [editingTitle, setEditingTitle] = useState(false)
    const [titleEditValue, setTitleEditValue] = useState('')
    const titleInputRef = useRef(null)
    const [editingFilename, setEditingFilename] = useState(false)
    const [filenameEditValue, setFilenameEditValue] = useState('')

    const isVideo = useMemo(() => {
        if (!asset) return false
        const mimeType = asset.mime_type || ''
        const filename = asset.original_filename || ''
        const videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']
        const ext = filename.split('.').pop()?.toLowerCase() || ''
        return mimeType.startsWith('video/') || videoExtensions.includes(ext)
    }, [asset])

    useEffect(() => {
        if (isOpen && asset?.id) {
            fetchMetadata()
        }
    }, [isOpen, asset?.id])

    useEffect(() => {
        if (!isOpen || !asset?.id) {
            setEditingTitle(false)
            setEditingFilename(false)
        }
    }, [isOpen, asset?.id])

    // Enter animation: start off-screen, then slide in
    useEffect(() => {
        if (!isOpen) {
            setHasEntered(false)
            setIsExiting(false)
            return
        }
        setHasEntered(false)
        const frame = requestAnimationFrame(() => {
            requestAnimationFrame(() => setHasEntered(true))
        })
        return () => cancelAnimationFrame(frame)
    }, [isOpen])

    // Exit animation: slide out, then notify parent
    useEffect(() => {
        if (!isExiting) return
        const t = setTimeout(() => onClose?.(), 320)
        return () => clearTimeout(t)
    }, [isExiting, onClose])

    useEffect(() => {
        if (!isOpen || !asset?.id) {
            setAssetCollections([])
            return
        }
        setAssetCollectionsLoading(true)
        window.axios
            .get(`/app/assets/${asset.id}/collections`, { headers: { Accept: 'application/json' } })
            .then((res) => setAssetCollections((res.data?.collections ?? []).filter(Boolean)))
            .catch(() => setAssetCollections([]))
            .finally(() => setAssetCollectionsLoading(false))
    }, [isOpen, asset?.id])

    useEffect(() => {
        if (!isOpen || !asset?.id) {
            setDropdownCollections([])
            return
        }
        setDropdownCollectionsLoading(true)
        window.axios
            .get('/app/collections/list', { headers: { Accept: 'application/json' } })
            .then((res) => setDropdownCollections(res.data?.collections ?? []))
            .catch(() => setDropdownCollections([]))
            .finally(() => setDropdownCollectionsLoading(false))
    }, [isOpen, asset?.id])

    useEffect(() => {
        if (externalActivityEvents !== null && externalActivityLoading !== undefined) {
            return
        }
        if (!isOpen || !asset?.id) {
            setActivityEvents([])
            return
        }
        setActivityLoading(true)
        window.axios
            .get(`/app/assets/${asset.id}/activity`)
            .then((res) => setActivityEvents(res.data?.events ?? []))
            .catch(() => setActivityEvents([]))
            .finally(() => setActivityLoading(false))
    }, [isOpen, asset?.id, externalActivityEvents, externalActivityLoading])

    const fetchMetadata = async () => {
        if (!asset?.id) return
        setLoading(true)
        setError(null)
        try {
            const response = await window.axios.get(`/app/assets/${asset.id}/metadata/all`)
            setMetadata(response.data)
        } catch (err) {
            setError(err.response?.data?.message || 'Failed to load metadata')
        } finally {
            setLoading(false)
        }
    }

    useEffect(() => {
        const handleClickOutside = (e) => {
            if (actionsDropdownRef.current && !actionsDropdownRef.current.contains(e.target)) {
                setShowActionsDropdown(false)
            }
        }
        if (showActionsDropdown) {
            document.addEventListener('mousedown', handleClickOutside)
            return () => document.removeEventListener('mousedown', handleClickOutside)
        }
    }, [showActionsDropdown])

    const handlePublish = async () => {
        if (!asset?.id || !canPublishWithFallback) return
        setPublishing(true)
        setLifecycleError(null)
        try {
            await window.axios.post(`/app/assets/${asset.id}/publish`)
            router.reload({ preserveState: true, preserveScroll: true })
        } catch (err) {
            setLifecycleError(err.response?.data?.message || err.message || 'Failed to publish')
        } finally {
            setPublishing(false)
        }
    }
    const handleUnpublish = async () => {
        if (!asset?.id || !canUnpublishWithFallback) return
        setUnpublishing(true)
        setLifecycleError(null)
        try {
            await window.axios.post(`/app/assets/${asset.id}/unpublish`)
            router.reload({ preserveState: true, preserveScroll: true })
        } catch (err) {
            setLifecycleError(err.response?.data?.message || err.message || 'Failed to unpublish')
        } finally {
            setUnpublishing(false)
        }
    }
    const handleArchive = async () => {
        if (!asset?.id || !canArchiveWithFallback) return
        setArchiving(true)
        setLifecycleError(null)
        try {
            await window.axios.post(`/app/assets/${asset.id}/archive`)
            router.reload({ preserveState: true, preserveScroll: true })
        } catch (err) {
            setLifecycleError(err.response?.data?.message || err.message || 'Failed to archive')
        } finally {
            setArchiving(false)
        }
    }
    const handleRestore = async () => {
        if (!asset?.id || !canRestoreWithFallback) return
        setRestoring(true)
        setLifecycleError(null)
        try {
            await window.axios.post(`/app/assets/${asset.id}/restore`)
            router.reload({ preserveState: true, preserveScroll: true })
        } catch (err) {
            setLifecycleError(err.response?.data?.message || err.message || 'Failed to restore')
        } finally {
            setRestoring(false)
        }
    }
    const handleRegenerateAiMetadata = async () => {
        if (!asset?.id || !canRegenerateAiMetadataForTroubleshooting) return
        setRegeneratingAiMetadata(true)
        try {
            const res = await window.axios.post(`/app/assets/${asset.id}/ai-metadata/regenerate`)
            if (res.data?.success) setTimeout(fetchMetadata, 1500)
        } finally {
            setRegeneratingAiMetadata(false)
        }
    }
    const handleRegenerateSystemMetadata = async () => {
        if (!asset?.id || !canRegenerateAiMetadataForTroubleshooting) return
        setRegeneratingSystemMetadata(true)
        try {
            const res = await window.axios.post(`/app/assets/${asset.id}/system-metadata/regenerate`)
            if (res.data?.success) setTimeout(fetchMetadata, 1500)
        } finally {
            setRegeneratingSystemMetadata(false)
        }
    }
    const handleRegenerateAiTagging = async () => {
        if (!asset?.id || !canRegenerateAiMetadataForTroubleshooting) return
        setRegeneratingAiTagging(true)
        try {
            const res = await window.axios.post(`/app/assets/${asset.id}/ai-tagging/regenerate`)
            if (res.data?.success) setTimeout(fetchMetadata, 1500)
        } finally {
            setRegeneratingAiTagging(false)
        }
    }
    const handleRegenerateThumbnails = async () => {
        if (!asset?.id || !canRegenerateThumbnailsAdmin) return
        setRegeneratingThumbnails(true)
        try {
            await window.axios.post(`/app/assets/${asset.id}/thumbnails/regenerate-styles`, {
                styles: ['thumb', 'medium', 'large'],
                force_imagick: false,
            })
            fetchMetadata()
            router.reload({ only: ['asset', 'auth'], preserveState: false })
        } finally {
            setRegeneratingThumbnails(false)
        }
    }
    const handleRegenerateVideoThumbnail = async () => {
        if (!asset?.id) return
        setRegeneratingVideoThumbnail(true)
        try {
            await window.axios.post(`/app/assets/${asset.id}/thumbnails/regenerate-video-thumbnail`)
            router.reload({ preserveState: true, preserveScroll: true })
        } finally {
            setRegeneratingVideoThumbnail(false)
        }
    }
    const handleRegenerateVideoPreview = async () => {
        if (!asset?.id) return
        setRegeneratingVideoPreview(true)
        try {
            await window.axios.post(`/app/assets/${asset.id}/thumbnails/regenerate-video-preview`)
            router.reload({ preserveState: true, preserveScroll: true })
        } finally {
            setRegeneratingVideoPreview(false)
        }
    }
    const handleRemovePreview = async () => {
        if (!asset?.id) return
        setRemovePreviewLoading(true)
        try {
            await window.axios.delete(`/app/assets/${asset.id}/thumbnails/preview`)
            fetchMetadata()
            router.reload({ preserveScroll: true, only: ['asset', 'auth'] })
        } finally {
            setRemovePreviewLoading(false)
        }
    }

    const hasValue = (value, type) => {
        if (value === null || value === undefined) return false
        if (type === 'multiselect' && Array.isArray(value)) return value.length > 0
        return value !== ''
    }
    const formatValue = (value, type) => {
        if (!hasValue(value, type)) return null
        if (type === 'multiselect' && Array.isArray(value)) {
            return value.map((v, i) => (i < value.length - 1 ? `${v}, ` : v))
        }
        if (type === 'boolean') return value ? 'Yes' : 'No'
        if (type === 'date') {
            try {
                return new Date(value).toLocaleDateString()
            } catch {
                return String(value)
            }
        }
        return String(value)
    }
    const getSourceBadge = (field) => {
        if (!field.metadata) return null
        const { source, producer, confidence, is_overridden } = field.metadata
        if (is_overridden)
            return (
                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">
                    Manual Override
                </span>
            )
        if (source === 'ai' || producer === 'ai')
            return (
                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-pink-100 text-pink-800">
                    AI {confidence ? `(${(confidence * 100).toFixed(0)}%)` : ''}
                </span>
            )
        if (source === 'user')
            return (
                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                    User
                </span>
            )
        if (source === 'automatic' || source === 'system')
            return (
                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                    {producer === 'system' ? 'System' : 'Automatic'}
                </span>
            )
        return null
    }

    const metadataByGroup = useMemo(() => {
        if (!metadata?.fields) return []
        const filtered = metadata.fields.filter((f) => !isExcludedFromGenericLoop(f))
        const byGroup = {}
        filtered.forEach((f) => {
            const key = f.group_key || 'general'
            if (!byGroup[key]) byGroup[key] = []
            byGroup[key].push(f)
        })
        return Object.entries(byGroup).map(([key, fields]) => ({ key, fields }))
    }, [metadata])

    const saveMetadataGroup = async (groupKey) => {
        const dirty = metadataDirty[groupKey]
        if (!dirty || !asset?.id) return
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content
        for (const [fieldId, value] of Object.entries(dirty)) {
            await fetch(`/app/assets/${asset.id}/metadata/edit`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf || '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ metadata_field_id: Number(fieldId), value }),
            })
        }
        setMetadataDirty((prev) => {
            const next = { ...prev }
            delete next[groupKey]
            return next
        })
        setMetadataEditGroup(null)
        fetchMetadata()
    }

    const displayActivityEvents = externalActivityEvents !== null ? externalActivityEvents : activityEvents
    const displayActivityLoading = externalActivityEvents !== null ? externalActivityLoading : activityLoading

    if (!isOpen) return null
    if (!canViewAsset) return null

    const panelWidth = fullPage ? '100%' : '92vw'

    const handleRequestClose = () => {
        if (fullPage) {
            onClose?.()
        } else {
            setIsExiting(true)
        }
    }

    const panelSlideClass = hasEntered && !isExiting ? 'translate-x-0' : 'translate-x-full'
    const backdropOpacityClass = hasEntered && !isExiting ? 'opacity-100' : 'opacity-0'
    const backdropPointerClass = isExiting ? 'pointer-events-none' : ''

    return (
        <>
            {!fullPage && (
                <div
                    className={`fixed inset-0 bg-black/30 z-40 transition-opacity duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] ${backdropOpacityClass} ${backdropPointerClass}`}
                    aria-hidden
                    onClick={handleRequestClose}
                />
            )}
            <div
                className={`fixed top-0 right-0 h-full bg-white shadow-2xl z-50 flex flex-col transition-transform duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] ${panelSlideClass}`}
                style={{ width: panelWidth, maxWidth: fullPage ? '100%' : '1240px' }}
                role="dialog"
                aria-labelledby="asset-detail-panel-title"
            >
                {/* Sticky Header */}
                <header className="sticky top-0 z-10 bg-white border-b border-gray-200 pb-3 mb-4 flex-shrink-0">
                    <div className="p-4 space-y-3">
                        {/* Row: Title + Actions + Close */}
                        <div className="flex items-start justify-between gap-3">
                            <div className="flex-1 min-w-0 flex items-start gap-2">
                                {editingTitle && canEditMetadata && (() => {
                                    const titleField = metadata?.fields?.find((f) => (f.key || f.field_key) === 'title')
                                    if (!titleField) return null
                                    const fid = titleField.metadata_field_id ?? titleField.field_id
                                    const saveTitle = async () => {
                                        const val = titleEditValue.trim()
                                        const csrf = document.querySelector('meta[name="csrf-token"]')?.content
                                        try {
                                            await fetch(`/app/assets/${asset.id}/metadata/edit`, {
                                                method: 'POST',
                                                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf || '' },
                                                credentials: 'same-origin',
                                                body: JSON.stringify({ metadata_field_id: fid, value: val || (asset?.original_filename ? asset.original_filename.replace(/\.[^.]+$/, '') : '') }),
                                            })
                                            router.reload({ preserveState: true, preserveScroll: true })
                                        } catch (e) {
                                            console.error('Failed to save title', e)
                                        }
                                        setEditingTitle(false)
                                    }
                                    return (
                                        <div className="flex-1 min-w-0 flex items-center gap-2">
                                            <input
                                                ref={titleInputRef}
                                                type="text"
                                                value={titleEditValue}
                                                onChange={(e) => setTitleEditValue(e.target.value)}
                                                onBlur={saveTitle}
                                                onKeyDown={(e) => { if (e.key === 'Enter') { e.target.blur(); saveTitle(); } }}
                                                className="text-lg font-semibold text-gray-900 border border-gray-300 rounded px-2 py-1 w-full max-w-md focus:ring-2 focus:ring-offset-1 focus:border-gray-400"
                                                aria-label="Edit title"
                                            />
                                        </div>
                                    )
                                })()}
                                {(!editingTitle || !canEditMetadata) && (
                                    <div className="flex-1 min-w-0">
                                        <h2 id="asset-detail-panel-title" className="text-lg font-semibold text-gray-900 truncate">
                                            {asset?.title || asset?.original_filename || 'Asset'}
                                        </h2>
                                        {canEditMetadata && metadata?.fields?.some((f) => (f.key || f.field_key) === 'title') && (
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setTitleEditValue(asset?.title || asset?.original_filename || '')
                                                    setEditingTitle(true)
                                                    setTimeout(() => titleInputRef.current?.focus(), 0)
                                                }}
                                                className="mt-0.5 inline-flex items-center gap-1 text-xs text-gray-500 hover:text-gray-700"
                                                aria-label="Edit title"
                                            >
                                                <PencilIcon className="h-3.5 w-3.5" />
                                                Edit
                                            </button>
                                        )}
                                    </div>
                                )}
                                <div className="flex items-center gap-1 flex-shrink-0">
                                    {metadata?.fields && (() => {
                                        const starredField = metadata.fields.find((f) => (f.key || f.field_key) === 'starred')
                                        const canToggleStar = starredField && canEditMetadata && !starredField.readonly
                                        const isStarred = asset?.starred === true
                                        const toggleStar = async () => {
                                            if (!canToggleStar || !asset?.id || !starredField) return
                                            const fid = starredField.metadata_field_id ?? starredField.field_id
                                            const csrf = document.querySelector('meta[name="csrf-token"]')?.content
                                            try {
                                                await fetch(`/app/assets/${asset.id}/metadata/edit`, {
                                                    method: 'POST',
                                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf || '' },
                                                    credentials: 'same-origin',
                                                    body: JSON.stringify({ metadata_field_id: fid, value: !isStarred }),
                                                })
                                                router.reload({ preserveState: true, preserveScroll: true })
                                            } catch (e) {
                                                console.error('Failed to toggle star', e)
                                            }
                                        }
                                        return canToggleStar ? (
                                            <button
                                                type="button"
                                                onClick={toggleStar}
                                                className="p-1.5 rounded-md hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-offset-1"
                                                aria-label={isStarred ? 'Unstar' : 'Star'}
                                            >
                                                {isStarred ? (
                                                    <StarIconSolid className="h-5 w-5 text-amber-500" />
                                                ) : (
                                                    <StarIconOutline className="h-5 w-5 text-gray-400 hover:text-amber-500" />
                                                )}
                                            </button>
                                        ) : isStarred ? (
                                            <StarIconSolid className="h-5 w-5 text-amber-500 ml-1" aria-hidden />
                                        ) : null
                                    })()}
                                </div>
                            </div>
                            <div className="flex items-center gap-2 flex-shrink-0">
                                {lifecycleError && (
                                    <p className="text-sm text-red-600 max-w-[10rem] truncate" title={lifecycleError}>
                                        {lifecycleError}
                                    </p>
                                )}
                                <div className="relative" ref={actionsDropdownRef}>
                                    <button
                                        type="button"
                                        onClick={() => setShowActionsDropdown(!showActionsDropdown)}
                                        className="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                                    >
                                        Actions
                                        <ChevronDownIcon
                                            className={`ml-2 h-4 w-4 ${showActionsDropdown ? 'rotate-180' : ''}`}
                                        />
                                    </button>
                                    {showActionsDropdown && (
                                        <div className="absolute right-0 z-20 mt-2 w-60 origin-top-right rounded-lg bg-white shadow-lg ring-1 ring-black ring-opacity-5 py-2">
                                            {/* Section 1 — Primary Actions */}
                                            <div className="px-2 py-1">
                                                {canPublishWithFallback && asset?.is_published === false && !asset?.archived_at && (
                                                    <button
                                                        type="button"
                                                        onClick={() => { setShowActionsDropdown(false); handlePublish(); }}
                                                        disabled={publishing}
                                                        className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md flex items-center gap-2"
                                                    >
                                                        <CheckCircleIcon className="h-4 w-4 flex-shrink-0" />
                                                        Publish
                                                    </button>
                                                )}
                                                {canUnpublishWithFallback && asset?.is_published === true && !asset?.archived_at && (
                                                    <button
                                                        type="button"
                                                        onClick={() => { setShowActionsDropdown(false); handleUnpublish(); }}
                                                        disabled={unpublishing}
                                                        className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md flex items-center gap-2"
                                                    >
                                                        <XCircleIcon className="h-4 w-4 flex-shrink-0" />
                                                        Unpublish
                                                    </button>
                                                )}
                                                {canArchiveWithFallback && !asset?.archived_at && (
                                                    <button
                                                        type="button"
                                                        onClick={() => { setShowActionsDropdown(false); handleArchive(); }}
                                                        disabled={archiving}
                                                        className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md flex items-center gap-2"
                                                    >
                                                        <ArchiveBoxIcon className="h-4 w-4 flex-shrink-0" />
                                                        Archive
                                                    </button>
                                                )}
                                                {canRestoreWithFallback && asset?.archived_at && (
                                                    <button
                                                        type="button"
                                                        onClick={() => { setShowActionsDropdown(false); handleRestore(); }}
                                                        disabled={restoring}
                                                        className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md flex items-center gap-2"
                                                    >
                                                        <ArrowUturnLeftIcon className="h-4 w-4 flex-shrink-0" />
                                                        Restore
                                                    </button>
                                                )}
                                                {onReplaceFile && (
                                                    <button
                                                        type="button"
                                                        onClick={() => { setShowActionsDropdown(false); onReplaceFile(); }}
                                                        className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md flex items-center gap-2"
                                                    >
                                                        <ArrowPathIcon className="h-4 w-4 flex-shrink-0" />
                                                        Replace file
                                                    </button>
                                                )}
                                            </div>
                                            {(canRegenerateAiMetadataForTroubleshooting || canRegenerateThumbnailsAdmin) && (
                                                <>
                                                    <div className="border-t border-gray-100 my-2" />
                                                    <div className="px-2 py-1">
                                                        <p className="px-3 py-1 text-xs font-medium text-gray-400 uppercase tracking-wider">Reprocess</p>
                                                        {canRegenerateAiMetadataForTroubleshooting && (
                                                            <>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => { setShowActionsDropdown(false); handleRegenerateAiMetadata(); }}
                                                                    disabled={regeneratingAiMetadata}
                                                                    className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md flex items-center gap-2"
                                                                >
                                                                    <ArrowPathIcon className="h-4 w-4 flex-shrink-0" />
                                                                    Re-run AI analysis
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => { setShowActionsDropdown(false); handleRegenerateSystemMetadata(); }}
                                                                    disabled={regeneratingSystemMetadata}
                                                                    className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md flex items-center gap-2"
                                                                >
                                                                    <ArrowPathIcon className="h-4 w-4 flex-shrink-0" />
                                                                    Reprocess metadata
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => { setShowActionsDropdown(false); handleRegenerateAiTagging(); }}
                                                                    disabled={regeneratingAiTagging}
                                                                    className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md flex items-center gap-2"
                                                                >
                                                                    <ArrowPathIcon className="h-4 w-4 flex-shrink-0" />
                                                                    Reprocess tags
                                                                </button>
                                                            </>
                                                        )}
                                                        {canRegenerateThumbnailsAdmin && (
                                                            <button
                                                                type="button"
                                                                onClick={() => { setShowActionsDropdown(false); handleRegenerateThumbnails(); }}
                                                                disabled={regeneratingThumbnails}
                                                                className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md flex items-center gap-2"
                                                            >
                                                                <ArrowPathIcon className="h-4 w-4 flex-shrink-0" />
                                                                Regenerate previews
                                                            </button>
                                                        )}
                                                    </div>
                                                </>
                                            )}
                                            {(supportsThumbnail(asset?.mime_type, asset?.file_extension || asset?.original_filename?.split?.('.')?.pop()) || isVideo) && (
                                                <>
                                                    <div className="border-t border-gray-100 my-2" />
                                                    <div className="px-2 py-1">
                                                        {supportsThumbnail(asset?.mime_type, asset?.file_extension || asset?.original_filename?.split?.('.')?.pop()) && (
                                                            <button
                                                                type="button"
                                                                onClick={() => { setShowActionsDropdown(false); handleRemovePreview(); }}
                                                                disabled={removePreviewLoading}
                                                                className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md flex items-center gap-2"
                                                            >
                                                                <TrashIcon className="h-4 w-4 flex-shrink-0" />
                                                                Remove preview
                                                            </button>
                                                        )}
                                                        {isVideo && (
                                                            <button
                                                                type="button"
                                                                onClick={() => { setShowActionsDropdown(false); handleRegenerateVideoPreview(); }}
                                                                disabled={regeneratingVideoPreview}
                                                                className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-md flex items-center gap-2"
                                                            >
                                                                <ArrowPathIcon className="h-4 w-4 flex-shrink-0" />
                                                                Regenerate video preview
                                                            </button>
                                                        )}
                                                    </div>
                                                </>
                                            )}
                                            {onDelete && (
                                                <>
                                                    <div className="border-t border-gray-100 my-2" />
                                                    <div className="px-2 py-1">
                                                        <p className="px-3 py-1 text-xs font-medium text-red-600 uppercase tracking-wider">Danger zone</p>
                                                        <button
                                                            type="button"
                                                            onClick={() => { setShowActionsDropdown(false); onDelete(); }}
                                                            className="w-full text-left px-3 py-2 text-sm text-red-700 hover:bg-red-50 rounded-md flex items-center gap-2 font-medium"
                                                        >
                                                            <TrashIcon className="h-4 w-4 flex-shrink-0" />
                                                            Delete asset
                                                        </button>
                                                    </div>
                                                </>
                                            )}
                                        </div>
                                    )}
                                </div>
                                {!fullPage && (
                                    <button
                                        type="button"
                                        onClick={handleRequestClose}
                                        className="rounded-md p-2 text-gray-400 hover:text-gray-600"
                                        aria-label="Close"
                                    >
                                        <XMarkIcon className="h-6 w-6" />
                                    </button>
                                )}
                            </div>
                        </div>
                        {/* Filename (secondary, smaller) */}
                        <div className="flex items-center gap-2">
                            {editingFilename ? (
                                <div className="flex items-center gap-2 flex-1 min-w-0">
                                    <input
                                        type="text"
                                        value={filenameEditValue}
                                        onChange={(e) => setFilenameEditValue(e.target.value)}
                                        className="text-sm text-gray-600 border border-gray-300 rounded px-2 py-1 flex-1 max-w-sm focus:ring-2 focus:ring-offset-1"
                                        placeholder="Filename"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => { setEditingFilename(false); setFilenameEditValue(asset?.original_filename || ''); }}
                                        className="text-xs font-medium text-white rounded px-2 py-1"
                                        style={{ backgroundColor: brandPrimary }}
                                    >
                                        Save
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => { setFilenameEditValue(asset?.original_filename || ''); setEditingFilename(false); }}
                                        className="text-xs font-medium text-gray-600 hover:text-gray-900"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => { setFilenameEditValue(asset?.original_filename || ''); setEditingFilename(true); }}
                                    className="text-sm text-gray-500 truncate max-w-md hover:text-gray-700 text-left"
                                >
                                    {asset?.original_filename || '—'}
                                </button>
                            )}
                        </div>
                        {/* Badges: Category + Lifecycle only */}
                        <div className="flex flex-wrap items-center gap-2">
                            {metadata?.category && (
                                <span className="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-gray-100 text-gray-800">
                                    {metadata.category.name}
                                </span>
                            )}
                            {asset?.archived_at && (
                                <span className="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-gray-100 text-gray-700 border border-gray-300">
                                    Archived
                                </span>
                            )}
                            {asset?.is_published === true && !asset?.archived_at && (
                                <span className="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-green-100 text-green-700 border border-green-300">
                                    Published
                                </span>
                            )}
                            {asset?.is_published === false && !asset?.archived_at && (
                                <span className="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-yellow-100 text-yellow-700 border border-yellow-300">
                                    Unpublished
                                </span>
                            )}
                        </div>
                        {/* Preview: max-height 360px, neutral container, file type badge, expand toggle */}
                        <div className="relative rounded-lg bg-gray-100 border border-gray-200 overflow-hidden flex items-center justify-center" style={{ maxHeight: previewExpanded ? '70vh' : '360px', minHeight: '200px' }}>
                            {asset?.id && (
                                <ThumbnailPreview
                                    asset={asset}
                                    alt={asset?.title || asset?.original_filename || 'Preview'}
                                    className="w-full h-full object-contain"
                                    size="lg"
                                />
                            )}
                            <span className="absolute top-2 right-2 inline-flex items-center gap-1 rounded-md bg-white/90 px-2 py-1 text-xs font-medium text-gray-700 shadow-sm border border-gray-200">
                                <FileTypeIcon
                                    fileExtension={asset?.original_filename?.split?.('.')?.pop()}
                                    mimeType={asset?.mime_type}
                                    size="sm"
                                    iconClassName="text-gray-500"
                                />
                                {(asset?.original_filename?.split?.('.')?.pop() || asset?.mime_type?.split?.('/')?.[1] || 'file').toUpperCase()}
                            </span>
                            <button
                                type="button"
                                onClick={() => setPreviewExpanded(!previewExpanded)}
                                className="absolute bottom-2 right-2 rounded-md bg-white/90 p-2 text-gray-600 hover:text-gray-900 shadow-sm border border-gray-200"
                                aria-label={previewExpanded ? 'Collapse preview' : 'Expand preview'}
                            >
                                {previewExpanded ? (
                                    <ArrowsPointingInIcon className="h-4 w-4" />
                                ) : (
                                    <ArrowsPointingOutIcon className="h-4 w-4" />
                                )}
                            </button>
                        </div>
                        {isVideo && asset?.video_preview_url && (
                            <a
                                href={asset.video_preview_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center text-sm font-medium text-gray-600 hover:text-gray-900"
                            >
                                <PlayIcon className="h-4 w-4 mr-2" />
                                View preview video
                            </a>
                        )}
                    </div>
                </header>

                {/* Scrollable body */}
                <div className="flex-1 overflow-y-auto">
                    <div className="p-4 sm:p-5 divide-y divide-gray-200">
                        {loading && (
                            <div className="py-8 text-center text-sm text-gray-500">
                                Loading metadata…
                            </div>
                        )}
                        {error && (
                            <div className="p-4 bg-red-50 border-b border-red-100">
                                <p className="text-sm text-red-800">{error}</p>
                            </div>
                        )}

                        {/* Section 1 — Overview (expanded by default) */}
                        <section className="border-t border-gray-200 mb-6" aria-labelledby="section-overview">
                            <CollapsibleSection title="Overview" defaultExpanded={true}>
                                <div className="bg-white border border-gray-200 rounded-lg p-6 mb-6">
                                    <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                        {asset?.created_at && (
                                            <>
                                                <dt className="font-semibold text-gray-700">Created at</dt>
                                                <dd className="text-sm text-gray-900">{new Date(asset.created_at).toLocaleString()}</dd>
                                            </>
                                        )}
                                        {asset?.created_by && (
                                            <>
                                                <dt className="font-semibold text-gray-700">Created by</dt>
                                                <dd className="text-sm text-gray-900">
                                                    {asset.created_by.name ||
                                                        [asset.created_by.first_name, asset.created_by.last_name].filter(Boolean).join(' ') ||
                                                        '—'}
                                                </dd>
                                            </>
                                        )}
                                        {asset?.updated_at && (
                                            <>
                                                <dt className="font-semibold text-gray-700">Last modified</dt>
                                                <dd className="text-sm text-gray-900">{new Date(asset.updated_at).toLocaleString()}</dd>
                                            </>
                                        )}
                                        {auth?.approval_features?.approvals_enabled && asset?.approved_at && (
                                            <>
                                                <dt className="font-semibold text-gray-700">Approved at</dt>
                                                <dd className="text-sm text-gray-900">{new Date(asset.approved_at).toLocaleString()}</dd>
                                            </>
                                        )}
                                        {auth?.approval_features?.approvals_enabled && asset?.approved_by && (
                                            <>
                                                <dt className="font-semibold text-gray-700">Approved by</dt>
                                                <dd className="text-sm text-gray-900">{asset.approved_by.name || '—'}</dd>
                                            </>
                                        )}
                                        <dt className="font-semibold text-gray-700">Lifecycle</dt>
                                        <dd className="text-sm text-gray-900">
                                            {asset?.archived_at ? 'Archived' : asset?.is_published ? 'Published' : 'Unpublished'}
                                        </dd>
                                    </dl>
                                </div>
                            </CollapsibleSection>
                        </section>

                        {/* Section 2 — Metadata (grouped, section-based edit; expanded by default) */}
                        {!loading && !error && metadata && (
                            <section className="border-t border-gray-200 mb-6" aria-labelledby="section-metadata">
                                <CollapsibleSection title="Metadata" defaultExpanded={true}>
                                <div className="bg-white border border-gray-200 rounded-lg p-6 mb-6">
                                {metadataByGroup.map(({ key: groupKey, fields }) => {
                                    const isEditing = metadataEditGroup === groupKey
                                    const dirty = metadataDirty[groupKey]
                                    const canEdit = canEditMetadata && fields.some((f) => !f.readonly && f.population_mode !== 'automatic')
                                    return (
                                        <div
                                            key={groupKey}
                                            className={`mb-5 last:mb-0 rounded-lg border transition-colors ${isEditing ? 'bg-gray-50 border-gray-200 shadow-sm' : 'border-transparent'}`}
                                        >
                                            <div className="flex items-center justify-between px-3 pt-3 pb-2">
                                                <h4 className="text-sm font-semibold text-gray-800 flex items-center gap-2">
                                                    {groupLabel(groupKey)}
                                                    {dirty && Object.keys(dirty).length > 0 && (
                                                        <span className="text-amber-600 text-xs font-normal">Unsaved changes</span>
                                                    )}
                                                </h4>
                                                {canEdit && !isEditing && (
                                                    <button
                                                        type="button"
                                                        onClick={() => setMetadataEditGroup(groupKey)}
                                                        className="text-xs font-medium rounded px-2 py-1 focus:outline-none focus:ring-2 focus:ring-offset-1"
                                                        style={{ color: brandPrimary }}
                                                    >
                                                        Edit
                                                    </button>
                                                )}
                                            </div>
                                            <dl className="px-3 pb-3">
                                                {fields.map((field) => {
                                                    const metadataFieldId = field.metadata_field_id ?? field.field_id
                                                    const isEditableField =
                                                        canEditMetadata && !field.readonly && field.population_mode !== 'automatic'
                                                    const isSystemField = field.readonly || field.population_mode === 'automatic'
                                                    const widget = resolve(field, CONTEXT.DISPLAY)
                                                    const isDominantColors =
                                                        widget === WIDGET.DOMINANT_COLORS &&
                                                        Array.isArray(field.current_value) &&
                                                        field.current_value.some((c) => c?.hex)
                                                    const isRating = widget === WIDGET.RATING
                                                    const isToggleBoolean = widget === WIDGET.TOGGLE
                                                    const editValue =
                                                        isEditing && isEditableField && metadataDirty[groupKey]?.[metadataFieldId] !== undefined
                                                            ? metadataDirty[groupKey][metadataFieldId]
                                                            : field.current_value
                                                    const displayValue =
                                                        isDominantColors ? null : formatValue(isEditing && isEditableField ? editValue : field.current_value, field.type)
                                                    const dominantColorsArray =
                                                        isDominantColors && !isEditing ? field.current_value.filter((c) => c?.hex) : null
                                                    const setDirtyValue = (val) => {
                                                        setMetadataDirty((prev) => ({
                                                            ...prev,
                                                            [groupKey]: { ...(prev[groupKey] || {}), [metadataFieldId]: val },
                                                        }))
                                                    }
                                                    return (
                                                        <div
                                                            key={metadataFieldId}
                                                            className={`flex items-start justify-between gap-2 py-3 border-b border-gray-100 last:border-b-0 ${!isSystemField ? 'group hover:bg-gray-50 cursor-pointer rounded-md px-2 -mx-2 transition' : ''}`}
                                                            title={isSystemField ? 'Automatically generated. Cannot be edited.' : undefined}
                                                        >
                                                            <div className="min-w-0 flex-1">
                                                                <span className="text-sm font-semibold text-gray-700">{field.display_label}</span>
                                                                {isEditing && isEditableField ? (
                                                                    <div className="mt-1">
                                                                        {isRating ? (
                                                                            <StarRating
                                                                                value={Number(editValue) || 0}
                                                                                onChange={(v) => setDirtyValue(v)}
                                                                                editable
                                                                                maxStars={5}
                                                                                size="md"
                                                                            />
                                                                        ) : isToggleBoolean ? (
                                                                            <label className="flex items-center gap-2 cursor-pointer">
                                                                                <div className="relative inline-flex items-center flex-shrink-0">
                                                                                    <input
                                                                                        type="checkbox"
                                                                                        checked={editValue === true || editValue === 'true'}
                                                                                        onChange={(e) => setDirtyValue(e.target.checked)}
                                                                                        className="sr-only peer"
                                                                                    />
                                                                                    <div
                                                                                        className="relative w-11 h-6 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-focus:outline-none peer-focus:ring-4"
                                                                                        style={{
                                                                                            ['--tw-ring-color']: brandPrimary,
                                                                                            ...(editValue === true || editValue === 'true' ? { backgroundColor: brandPrimary } : {}),
                                                                                        }}
                                                                                    />
                                                                                </div>
                                                                                <span className="text-sm text-gray-700">{editValue === true || editValue === 'true' ? 'Yes' : 'No'}</span>
                                                                            </label>
                                                                        ) : field.type === 'boolean' ? (
                                                                            <label className="flex items-center gap-2">
                                                                                <input
                                                                                    type="checkbox"
                                                                                    checked={!!editValue}
                                                                                    onChange={(e) => setDirtyValue(e.target.checked)}
                                                                                    className="rounded border-gray-300"
                                                                                />
                                                                                <span className="text-sm text-gray-700">{editValue ? 'Yes' : 'No'}</span>
                                                                            </label>
                                                                        ) : field.type === 'select' && Array.isArray(field.options) ? (
                                                                            <select
                                                                                value={editValue ?? ''}
                                                                                onChange={(e) => setDirtyValue(e.target.value)}
                                                                                className="mt-1 block w-full max-w-xs rounded-md border-gray-300 text-sm"
                                                                            >
                                                                                <option value="">—</option>
                                                                                {field.options.map((opt) => (
                                                                                    <option key={opt.value} value={opt.value}>
                                                                                        {opt.display_label ?? opt.value}
                                                                                    </option>
                                                                                ))}
                                                                            </select>
                                                                        ) : field.type === 'number' ? (
                                                                            <input
                                                                                type="number"
                                                                                value={editValue ?? ''}
                                                                                onChange={(e) => setDirtyValue(e.target.value === '' ? null : Number(e.target.value))}
                                                                                className="mt-1 block w-full max-w-xs rounded-md border-gray-300 text-sm"
                                                                            />
                                                                        ) : field.type === 'date' ? (
                                                                            <input
                                                                                type="date"
                                                                                value={editValue ? (typeof editValue === 'string' ? editValue.slice(0, 10) : new Date(editValue).toISOString().slice(0, 10)) : ''}
                                                                                onChange={(e) => setDirtyValue(e.target.value || null)}
                                                                                className="mt-1 block w-full max-w-xs rounded-md border-gray-300 text-sm"
                                                                            />
                                                                        ) : (
                                                                            <input
                                                                                type="text"
                                                                                value={Array.isArray(editValue) ? (editValue || []).join(', ') : (editValue ?? '')}
                                                                                onChange={(e) => {
                                                                                    const v = e.target.value
                                                                                    if (field.type === 'multiselect') {
                                                                                        setDirtyValue(v ? v.split(',').map((s) => s.trim()).filter(Boolean) : [])
                                                                                    } else {
                                                                                        setDirtyValue(v)
                                                                                    }
                                                                                }}
                                                                                className="mt-1 block w-full max-w-xs rounded-md border-gray-300 text-sm"
                                                                            />
                                                                        )}
                                                                    </div>
                                                                ) : isRating ? (
                                                                    <span className="inline-flex items-center mt-1">
                                                                        <StarRating
                                                                            value={Number(field.current_value) || 0}
                                                                            editable={false}
                                                                            maxStars={5}
                                                                            size="md"
                                                                        />
                                                                    </span>
                                                                ) : isToggleBoolean ? (
                                                                    <span className="text-sm text-gray-900 mt-1">
                                                                        {field.current_value === true || field.current_value === 'true' ? 'Yes' : 'No'}
                                                                    </span>
                                                                ) : (displayValue || dominantColorsArray) ? (
                                                                    <>
                                                                        <span className="text-gray-400 mx-1">:</span>
                                                                        <span className={isSystemField ? 'text-sm text-gray-600' : 'text-sm text-gray-900'}>
                                                                            {dominantColorsArray ? (
                                                                                <DominantColorsSwatches dominantColors={dominantColorsArray} />
                                                                            ) : (
                                                                                displayValue
                                                                            )}
                                                                        </span>
                                                                    </>
                                                                ) : null}
                                                            </div>
                                                            <div className="flex-shrink-0 flex items-center gap-2">
                                                                {isSystemField ? (
                                                                    <>
                                                                        <LockClosedIcon className="h-3.5 w-3.5 text-gray-400" aria-hidden />
                                                                        <span className="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">System</span>
                                                                    </>
                                                                ) : (
                                                                    <>
                                                                        {getSourceBadge(field)}
                                                                        {canEditMetadata && !isEditing && (
                                                                            <span className="opacity-0 group-hover:opacity-100 transition" aria-hidden>
                                                                                <PencilIcon className="h-3.5 w-3.5 text-gray-400" />
                                                                            </span>
                                                                        )}
                                                                    </>
                                                                )}
                                                            </div>
                                                        </div>
                                                    )
                                                })}
                                            </dl>
                                            {hasCollectionField(metadata.fields) && groupKey === 'general' && (
                                                <div className="flex items-start justify-between gap-2 py-3 border-b border-gray-100 last:border-b-0 px-3">
                                                    <span className="text-sm font-semibold text-gray-700">Collection</span>
                                                    <div className="min-w-0 flex-1 flex justify-end">
                                                        {isEditing && canEdit ? (
                                                            <div className="w-full max-w-sm">
                                                                {dropdownCollectionsLoading ? (
                                                                    <p className="text-sm text-gray-500">Loading collections…</p>
                                                                ) : (
                                                                    <CollectionSelector
                                                                        collections={dropdownCollections}
                                                                        selectedIds={(assetCollections || []).filter(Boolean).map((c) => c?.id).filter(Boolean)}
                                                                        onChange={async (newCollectionIds) => {
                                                                            if (!asset?.id || syncCollectionsLoading) return
                                                                            setSyncCollectionsLoading(true)
                                                                            try {
                                                                                await window.axios.put(
                                                                                    `/app/assets/${asset.id}/collections`,
                                                                                    { collection_ids: newCollectionIds },
                                                                                    { headers: { Accept: 'application/json' } }
                                                                                )
                                                                                const res = await window.axios.get(`/app/assets/${asset.id}/collections`, { headers: { Accept: 'application/json' } })
                                                                                setAssetCollections((res.data?.collections ?? []).filter(Boolean))
                                                                            } catch (err) {
                                                                                const res = await window.axios.get(`/app/assets/${asset.id}/collections`, { headers: { Accept: 'application/json' } })
                                                                                setAssetCollections((res.data?.collections ?? []).filter(Boolean))
                                                                            } finally {
                                                                                setSyncCollectionsLoading(false)
                                                                            }
                                                                        }}
                                                                        disabled={syncCollectionsLoading}
                                                                        placeholder="Select collections…"
                                                                        maxHeight="320px"
                                                                    />
                                                                )}
                                                            </div>
                                                        ) : (
                                                            <span className="text-sm text-gray-900">
                                                                {assetCollectionsLoading
                                                                    ? 'Loading…'
                                                                    : assetCollections.length > 0
                                                                      ? assetCollections.map((c) => c.name).join(', ')
                                                                      : 'None'}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            )}
                                            {isEditing && canEdit && (
                                                <div className="sticky bottom-0 left-0 right-0 flex items-center justify-end gap-2 px-3 py-3 mt-2 bg-gray-50 border-t border-gray-200 rounded-b-lg">
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setMetadataEditGroup(null)
                                                            setMetadataDirty((prev) => {
                                                                const next = { ...prev }
                                                                delete next[groupKey]
                                                                return next
                                                            })
                                                        }}
                                                        className="text-sm font-medium text-gray-600 hover:text-gray-900 px-3 py-1.5"
                                                    >
                                                        Cancel
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => saveMetadataGroup(groupKey)}
                                                        disabled={!dirty || Object.keys(dirty).length === 0}
                                                        className="text-sm font-medium text-white rounded-md px-3 py-1.5 disabled:opacity-50 disabled:cursor-not-allowed"
                                                        style={{ backgroundColor: brandPrimary }}
                                                    >
                                                        Save
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    )
                                })}
                                </div>
                                </CollapsibleSection>
                            </section>
                        )}

                        {/* Section 3 — File Information (at least quick-view parity: status + tooltip, publish/who, filename editable) */}
                        <section className="border-t border-gray-200 mb-6" aria-labelledby="section-file">
                            <CollapsibleSection title="File information" defaultExpanded={true}>
                                <div className="bg-white border border-gray-200 rounded-lg p-6 mb-6">
                                    <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                        {/* Filename (editable when permission) */}
                                        <div className="sm:col-span-2">
                                            <dt className="font-semibold text-gray-700 mb-1">Filename</dt>
                                            <dd className="text-sm text-gray-900">
                                                {editingFilename ? (
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <input
                                                            type="text"
                                                            value={filenameEditValue}
                                                            onChange={(e) => setFilenameEditValue(e.target.value)}
                                                            className="text-sm text-gray-800 border border-gray-300 rounded px-2 py-1 flex-1 min-w-0 max-w-md font-mono focus:ring-2 focus:ring-offset-1"
                                                            placeholder="Filename"
                                                        />
                                                        <button
                                                            type="button"
                                                            onClick={async () => {
                                                                if (filenameEditValue.trim() && asset?.id) {
                                                                    try {
                                                                        const csrf = document.querySelector('meta[name="csrf-token"]')?.content
                                                                        const res = await fetch(`/app/assets/${asset.id}/filename`, {
                                                                            method: 'PATCH',
                                                                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf || '' },
                                                                            credentials: 'same-origin',
                                                                            body: JSON.stringify({ original_filename: filenameEditValue.trim() }),
                                                                        })
                                                                        if (res.ok) {
                                                                            setEditingFilename(false)
                                                                            router.reload({ preserveState: true, preserveScroll: true })
                                                                        }
                                                                    } catch {
                                                                        setEditingFilename(false)
                                                                        setFilenameEditValue(asset?.original_filename || '')
                                                                    }
                                                                } else {
                                                                    setEditingFilename(false)
                                                                    setFilenameEditValue(asset?.original_filename || '')
                                                                }
                                                            }}
                                                            className="text-xs font-medium text-white rounded px-2 py-1"
                                                            style={{ backgroundColor: brandPrimary }}
                                                        >
                                                            Save
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => { setFilenameEditValue(asset?.original_filename || ''); setEditingFilename(false) }}
                                                            className="text-xs font-medium text-gray-600 hover:text-gray-900"
                                                        >
                                                            Cancel
                                                        </button>
                                                    </div>
                                                ) : (
                                                    <span className="font-mono break-all">
                                                        {asset?.original_filename || '—'}
                                                        {canEditMetadata && (
                                                            <button
                                                                type="button"
                                                                onClick={() => { setFilenameEditValue(asset?.original_filename || ''); setEditingFilename(true) }}
                                                                className="ml-2 inline-flex items-center gap-1 text-xs font-medium rounded focus:outline-none focus:ring-2 focus:ring-offset-1"
                                                                style={{ color: brandPrimary }}
                                                            >
                                                                <PencilIcon className="h-3.5 w-3.5" />
                                                                Edit
                                                            </button>
                                                        )}
                                                    </span>
                                                )}
                                            </dd>
                                        </div>
                                        {/* Status (thumbnail/visibility) with tooltip */}
                                        <dt className="font-semibold text-gray-700">Status</dt>
                                        <dd className="text-sm text-gray-900">
                                            {(() => {
                                                const status = (asset?.thumbnail_status ?? asset?.status ?? '').toString().toLowerCase()
                                                const label = status === 'completed' ? 'Completed' : status === 'processing' ? 'Processing' : status === 'failed' ? 'Failed' : status === 'skipped' ? 'Skipped' : status === 'pending' ? 'Pending' : (asset?.thumbnail_status ?? asset?.status ?? '—')
                                                const tooltip = status === 'completed' ? 'Thumbnail and preview generation completed.' : status === 'processing' ? 'Thumbnail or preview is being generated.' : status === 'failed' ? (asset?.thumbnail_error ? `Thumbnail generation failed: ${asset.thumbnail_error}` : 'Thumbnail generation failed.') : status === 'skipped' ? (asset?.metadata?.thumbnail_skip_reason ? `Preview skipped: ${asset.metadata.thumbnail_skip_reason}` : 'Preview not generated for this file type.') : 'Thumbnail or preview is pending.'
                                                return (
                                                    <span
                                                        title={tooltip}
                                                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${status === 'completed' ? 'bg-green-100 text-green-800' : status === 'processing' ? 'bg-amber-100 text-amber-800' : status === 'failed' ? 'bg-red-100 text-red-800' : status === 'skipped' ? 'bg-gray-100 text-gray-700' : 'bg-gray-100 text-gray-600'}`}
                                                    >
                                                        {label}
                                                    </span>
                                                )
                                            })()}
                                        </dd>
                                        {/* Category (grid sends asset.category; metadata may have category object) */}
                                        {(asset?.category?.name || metadata?.category?.name) && (
                                            <>
                                                <dt className="font-semibold text-gray-700">Category</dt>
                                                <dd className="text-sm text-gray-900">{asset?.category?.name || metadata?.category?.name}</dd>
                                            </>
                                        )}
                                        {/* Uploaded (created_at) */}
                                        {asset?.created_at && (
                                            <>
                                                <dt className="font-semibold text-gray-700">Uploaded</dt>
                                                <dd className="text-sm text-gray-900">{new Date(asset.created_at).toLocaleString()}</dd>
                                            </>
                                        )}
                                        {/* Published (date + by) when not redundant with Overview lifecycle */}
                                        {asset?.published_at && (
                                            <>
                                                <dt className="font-semibold text-gray-700">Published</dt>
                                                <dd className="text-sm text-gray-900">
                                                    {new Date(asset.published_at).toLocaleString()}
                                                    {asset.published_by && (
                                                        <span className="ml-1 text-gray-500">
                                                            by {asset.published_by.name || [asset.published_by.first_name, asset.published_by.last_name].filter(Boolean).join(' ') || '—'}
                                                        </span>
                                                    )}
                                                </dd>
                                            </>
                                        )}
                                        {/* Archived (date + by) */}
                                        {asset?.archived_at && (
                                            <>
                                                <dt className="font-semibold text-gray-700">Archived</dt>
                                                <dd className="text-sm text-gray-900">
                                                    {new Date(asset.archived_at).toLocaleString()}
                                                    {asset.archived_by && (
                                                        <span className="ml-1 text-gray-500">
                                                            by {asset.archived_by.name || [asset.archived_by.first_name, asset.archived_by.last_name].filter(Boolean).join(' ') || '—'}
                                                        </span>
                                                    )}
                                                </dd>
                                            </>
                                        )}
                                        <dt className="font-semibold text-gray-700">File type</dt>
                                        <dd className="text-sm text-gray-900">{asset?.mime_type || '—'}</dd>
                                        <dt className="font-semibold text-gray-700">File size</dt>
                                        <dd className="text-sm text-gray-900">
                                            {asset?.size_bytes != null && asset.size_bytes > 0
                                                ? (() => {
                                                    const b = Number(asset.size_bytes)
                                                    if (b < 1024) return `${b} B`
                                                    if (b < 1024 * 1024) return `${(b / 1024).toFixed(2)} KB`
                                                    if (b < 1024 * 1024 * 1024) return `${(b / (1024 * 1024)).toFixed(2)} MB`
                                                    return `${(b / (1024 * 1024 * 1024)).toFixed(2)} GB`
                                                })()
                                                : '—'}
                                        </dd>
                                        {((asset?.width != null && asset?.height != null) || (asset?.metadata?.image_width && asset?.metadata?.image_height)) && (
                                            <>
                                                <dt className="font-semibold text-gray-700">Dimensions</dt>
                                                <dd className="text-sm text-gray-900">
                                                    {asset?.width != null && asset?.height != null
                                                        ? `${asset.width} × ${asset.height}`
                                                        : `${asset.metadata?.image_width} × ${asset.metadata?.image_height}`}
                                                    {asset?.metadata?.dimensions && typeof asset.metadata.dimensions === 'string' && asset.metadata.dimensions.match(/px/i) && ` (${asset.metadata.dimensions})`}
                                                </dd>
                                            </>
                                        )}
                                        <dt className="font-semibold text-gray-700">Thumbnail status</dt>
                                        <dd className="text-sm text-gray-900">{asset?.thumbnail_status ?? '—'}</dd>
                                        {/* Asset ID (UUID) — at bottom for copy/reference */}
                                        {asset?.id && (
                                            <div className="sm:col-span-2 pt-3 mt-3 border-t border-gray-200">
                                                <dt className="font-semibold text-gray-700 mb-1">Asset ID</dt>
                                                <dd className="text-sm font-mono text-gray-900 break-all" title={asset.id}>
                                                    {asset.id}
                                                </dd>
                                            </div>
                                        )}
                                    </dl>
                                </div>
                            </CollapsibleSection>
                        </section>

                        {/* Section 4 — Activity (collapsed by default) */}
                        <section className="border-t border-gray-200 mb-6" aria-labelledby="section-activity">
                            <CollapsibleSection title="Activity" defaultExpanded={false}>
                                <div className="bg-white border border-gray-200 rounded-lg p-6 mb-6">
                                    <AssetTimeline events={displayActivityEvents} loading={displayActivityLoading} />
                                </div>
                            </CollapsibleSection>
                        </section>

                        {/* Section 5 — Versions (stub, collapsed by default) */}
                        <PermissionGate permission="asset.view">
                            <section className="border-t border-gray-200 mb-6" aria-labelledby="section-versions">
                                <CollapsibleSection title="Versions" defaultExpanded={false}>
                                    <div className="bg-white border border-gray-200 rounded-lg p-6 mb-6">
                                        <p className="text-sm text-gray-500">Coming soon.</p>
                                    </div>
                                </CollapsibleSection>
                            </section>
                        </PermissionGate>

                        {/* Section 6 — Approval Workflow (stub, collapsed by default) */}
                        <PermissionGate permission="asset.view">
                            <section className="border-t border-gray-200 mb-6" aria-labelledby="section-approval">
                                <CollapsibleSection title="Approval workflow" defaultExpanded={false}>
                                    <div className="bg-white border border-gray-200 rounded-lg p-6 mb-6">
                                        <p className="text-sm text-gray-500">Coming soon.</p>
                                    </div>
                                </CollapsibleSection>
                            </section>
                        </PermissionGate>

                        {/* Section 7 — Download History (collapsed by default; Tenant Owner/Admin, Brand Manager/Admin only) */}
                        {showDownloadHistory && (
                            <section className="border-t border-gray-200 mb-6" aria-labelledby="section-downloads">
                                <CollapsibleSection title="Download history" defaultExpanded={false}>
                                    <div className="bg-white border border-gray-200 rounded-lg p-6 mb-6">
                                        <p className="text-sm text-gray-500">No download history available for this asset.</p>
                                    </div>
                                </CollapsibleSection>
                            </section>
                        )}
                    </div>

                    {/* Tags at bottom */}
                    {!loading && asset?.id && (
                        <div className="p-8 border-t border-gray-200">
                            <AssetTagManager
                                asset={asset}
                                showTitle
                                showInput={false}
                                detailed
                                primaryColor={brandPrimary}
                            />
                        </div>
                    )}
                </div>

                {!fullPage && (
                    <div className="flex-shrink-0 border-t border-gray-200 p-4 bg-gray-50 flex justify-end">
                        <button
                            type="button"
                            onClick={handleRequestClose}
                            className="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                        >
                            Close
                        </button>
                    </div>
                )}
            </div>
        </>
    )
}
