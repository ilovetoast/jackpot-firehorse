/**
 * Asset Detail Panel (Asset Control Center)
 *
 * Phase O: Full-height slide-out panel (80% width) with structured sections,
 * permission-aware visibility, and section-based metadata editing.
 *
 * - Sticky header: preview, filename (slide-out), badges, star, actions (asset name lives in Overview)
 * - Section 1: Overview (asset name editable, dates, lifecycle)
 * - Section 2: Metadata (grouped; form-based edit per section if metadata.edit_post_upload)
 * - Section 3: File Information (read-only except filename if allowed)
 * - Section 4: Activity (collapsed by default)
 * - Section 5: Versions (plan-gated)
 * - Download history: admin/manager only, shown only when activity includes download events
 *
 * Reusable as full page in admin contexts via prop fullPage.
 */
import { useEffect, useState, useRef, useMemo, useCallback, Fragment } from 'react'
import {
    XMarkIcon,
    ChevronDownIcon,
    ChevronRightIcon,
    LockClosedIcon,
    PlayIcon,
    PencilIcon,
    ArrowsPointingOutIcon,
    ArrowsPointingInIcon,
    CheckIcon,
    ArrowDownTrayIcon,
    RectangleStackIcon,
} from '@heroicons/react/24/outline'
import ThumbnailPreview from './ThumbnailPreview'
import FileTypeIcon from './FileTypeIcon'
import DominantColorsSwatches from './DominantColorsSwatches'
import AssetTagManager from './AssetTagManager'
import AssetTimeline from './AssetTimeline'
import CollapsibleSection from './CollapsibleSection'
import GuidelinesFocalPointModal from './BrandGuidelines/GuidelinesFocalPointModal'
import AssetEmbeddedMetadataPanel from './AssetEmbeddedMetadataPanel'
import PermissionGate from './PermissionGate'
import StarRating from './StarRating'
import CollectionSelector from './Collections/CollectionSelector'
import { usePermission } from '../hooks/usePermission'
import { useBucketOptional } from '../contexts/BucketContext'
import { useSelectionOptional } from '../contexts/SelectionContext'
import { router, usePage } from '@inertiajs/react'
import { resolveTrackedSingleAssetFileUrl, saveUrlAsDownload } from '../utils/singleAssetDownload'
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

/** Human-readable line for asset.metadata AI tag inference (skipped vs ran vs zero results). */
function formatAiTagInferenceSummary(meta) {
    if (!meta || typeof meta !== 'object') return null
    const status = meta.ai_tag_inference_status
    if (!status) return null
    const map = {
        vision_skipped_no_inputs: 'Skipped — no metadata fields or tag inference to run',
        skipped_tags_not_eligible: 'Skipped — AI tags not enabled for this category',
        skipped_upload_opt_out: 'Skipped — upload disabled AI tagging',
        attempted_ok: `Ran — ${meta.ai_tag_candidates_created ?? 0} tag candidate(s)`,
        attempted_empty: 'Ran — no tag candidates passed filters',
    }
    let s = map[status] || status
    if (status === 'attempted_empty' && meta.ai_tag_inference_detail === 'empty_model') {
        s += ' (model returned no tags)'
    } else if (status === 'attempted_empty' && meta.ai_tag_inference_detail === 'no_tags_passed_filters') {
        s += ' (below confidence or filtered)'
    } else if (
        status === 'attempted_empty' &&
        typeof meta.ai_tag_inference_detail === 'string' &&
        meta.ai_tag_inference_detail.startsWith('rekognition_error:')
    ) {
        const kind = meta.ai_tag_inference_detail.replace(/^rekognition_error:/, '')
        s += ` (AWS Rekognition: ${kind})`
    }
    const st = meta.ai_tag_parse_stats
    if (st && typeof st === 'object' && (st.raw !== undefined || st.passed !== undefined)) {
        const parts = []
        if (st.raw != null) parts.push(`raw ${st.raw}`)
        if (st.passed != null) parts.push(`passed ${st.passed}`)
        if (st.rejected_low_conf) parts.push(`rejected ${st.rejected_low_conf}`)
        if (parts.length) s += ` — ${parts.join(', ')}`
    }
    return s
}

export default function AssetDetailPanel({
    asset,
    isOpen,
    onClose,
    activityEvents: externalActivityEvents = null,
    activityLoading: externalActivityLoading = false,
    onToast = null,
    primaryColor,
    fullPage = false,
    /** Render inside lightbox right column (no slide-out overlay, no backdrop) */
    embeddedInLightbox = false,
    /** 'readonly' = view-only (lightbox); no actions, edits, or processing triggers */
    mode = 'default',
    /** Lightbox CTA: return user to drawer (parent closes lightbox and focuses drawer) */
    onManageInDrawer = null,
}) {
    const { auth, download_policy_disable_single_asset: policyDisableSingleAsset = false } = usePage().props
    const brandPrimary = primaryColor || auth?.activeBrand?.primary_color || '#6366f1'
    const readonlyMode = mode === 'readonly'
    const selection = useSelectionOptional()
    const bucket = useBucketOptional()

    // Permissions
    const { can } = usePermission()
    const canViewAsset = can('asset.view')
    const canEditMetadata = can('metadata.edit_post_upload')
    const canRegenerateAiMetadata = can('assets.ai_metadata.regenerate')

    const tenantRole = auth?.tenant_role || null
    const brandRole = auth?.brand_role || null
    const isOwnerOrAdmin = tenantRole === 'owner' || tenantRole === 'admin'
    const canRegenerateAiMetadataForTroubleshooting = canRegenerateAiMetadata || isOwnerOrAdmin
    const canRestoreVersion = tenantRole === 'admin' || tenantRole === 'owner'

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
    const [versions, setVersions] = useState([])
    const [versionsLoading, setVersionsLoading] = useState(false)
    const [showRestoreModal, setShowRestoreModal] = useState(false)
    const [restoreVersion, setRestoreVersion] = useState(null)
    const [restorePreserveMetadata, setRestorePreserveMetadata] = useState(true)
    const [restoreRerunPipeline, setRestoreRerunPipeline] = useState(false)
    const [restoreLoading, setRestoreLoading] = useState(false)
    const [expandedVersionId, setExpandedVersionId] = useState(null)
    const [showVersionsWideModal, setShowVersionsWideModal] = useState(false)

    const [metadataEditGroup, setMetadataEditGroup] = useState(null)
    const [metadataDirty, setMetadataDirty] = useState({})
    /** Details panel (not quick view): schema fields vs embedded file metadata */
    const [detailMetadataTab, setDetailMetadataTab] = useState('fields')

    const [isExiting, setIsExiting] = useState(false)
    const [hasEntered, setHasEntered] = useState(false)
    const [previewExpanded, setPreviewExpanded] = useState(false)
    const [editingTitle, setEditingTitle] = useState(false)
    const [titleEditValue, setTitleEditValue] = useState('')
    const titleInputRef = useRef(null)
    const [editingFilename, setEditingFilename] = useState(false)
    const [filenameEditValue, setFilenameEditValue] = useState('')
    const [quickDownloadLoading, setQuickDownloadLoading] = useState(false)
    const [bucketToggleLoading, setBucketToggleLoading] = useState(false)
    const [focalModalOpen, setFocalModalOpen] = useState(false)
    const [focalAiRegenerateLoading, setFocalAiRegenerateLoading] = useState(false)

    const isVideo = useMemo(() => {
        if (!asset) return false
        const mimeType = asset.mime_type || ''
        const filename = asset.original_filename || ''
        const videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']
        const ext = filename.split('.').pop()?.toLowerCase() || ''
        return mimeType.startsWith('video/') || videoExtensions.includes(ext)
    }, [asset])

    const allowsFocalPoint = useMemo(() => {
        const m = asset?.mime_type || ''
        return !isVideo && m.startsWith('image/') && !m.includes('svg')
    }, [asset?.mime_type, isVideo])

    const aiEnabled = auth?.permissions?.ai_enabled !== false

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

    useEffect(() => {
        setDetailMetadataTab('fields')
    }, [asset?.id])

    // Enter animation: start off-screen, then slide in (skip for lightbox column)
    useEffect(() => {
        if (!isOpen) {
            setHasEntered(false)
            setIsExiting(false)
            return
        }
        if (embeddedInLightbox) {
            setHasEntered(true)
            return
        }
        setHasEntered(false)
        const frame = requestAnimationFrame(() => {
            requestAnimationFrame(() => setHasEntered(true))
        })
        return () => cancelAnimationFrame(frame)
    }, [isOpen, embeddedInLightbox])

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

    const planAllowsVersions = auth?.plan_allows_versions ?? false
    const fetchVersions = () => {
        if (!asset?.id || !planAllowsVersions) return
        setVersionsLoading(true)
        window.axios
            .get(`/app/assets/${asset.id}/versions`)
            .then((res) => {
                const data = res.data
                setVersions(Array.isArray(data) ? data : (data?.data ?? []))
            })
            .catch(() => setVersions([]))
            .finally(() => setVersionsLoading(false))
    }
    useEffect(() => {
        if (!isOpen || !asset?.id || !planAllowsVersions) {
            setVersions([])
            return
        }
        fetchVersions()
    }, [isOpen, asset?.id, planAllowsVersions])

    const handleRestoreVersion = async () => {
        if (!restoreVersion || !asset?.id || restoreLoading) return
        setRestoreLoading(true)
        try {
            await window.axios.post(`/app/assets/${asset.id}/versions/${restoreVersion.id}/restore`, {
                preserve_metadata: restorePreserveMetadata,
                rerun_pipeline: restoreRerunPipeline,
            })
            setShowRestoreModal(false)
            setRestoreVersion(null)
            setRestorePreserveMetadata(true)
            setRestoreRerunPipeline(false)
            fetchVersions()
            onToast?.('Version restored', 'success')
        } catch (err) {
            onToast?.(err.response?.data?.message || 'Failed to restore version', 'error')
        } finally {
            setRestoreLoading(false)
        }
    }

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
        const dark = embeddedInLightbox
        const { source, producer, confidence, is_overridden } = field.metadata
        if (is_overridden)
            return (
                <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${dark ? 'bg-neutral-800 text-neutral-200' : 'bg-yellow-100 text-yellow-800'}`}>
                    Manual Override
                </span>
            )
        if (source === 'ai' || producer === 'ai')
            return (
                <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${dark ? 'bg-neutral-800 text-neutral-200' : 'bg-pink-100 text-pink-800'}`}>
                    AI {confidence ? `(${(confidence * 100).toFixed(0)}%)` : ''}
                </span>
            )
        if (source === 'user')
            return (
                <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${dark ? 'bg-neutral-800 text-neutral-200' : 'bg-blue-100 text-blue-800'}`}>
                    User
                </span>
            )
        if (source === 'automatic' || source === 'system')
            return (
                <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${dark ? 'bg-neutral-800 text-neutral-200' : 'bg-purple-100 text-purple-800'}`}>
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
        const errors = []
        try {
            for (const [fieldId, value] of Object.entries(dirty)) {
                const res = await fetch(`/app/assets/${asset.id}/metadata/edit`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf || '',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ metadata_field_id: Number(fieldId), value }),
                })
                let data = {}
                try {
                    data = await res.json()
                } catch {
                    /* non-JSON body */
                }
                if (!res.ok) {
                    const label =
                        metadata?.fields?.find(
                            (f) => String(f.metadata_field_id ?? f.field_id) === String(fieldId)
                        )?.display_label || fieldId
                    errors.push(data.message ? `${label}: ${data.message}` : `${label}: save failed (${res.status})`)
                }
            }
            if (errors.length > 0) {
                onToast?.(errors.join(' · '), 'error')
                return
            }
            setMetadataDirty((prev) => {
                const next = { ...prev }
                delete next[groupKey]
                return next
            })
            setMetadataEditGroup(null)
            onToast?.('Metadata saved', 'success')
            fetchMetadata()
        } catch (e) {
            onToast?.(e?.message || 'Failed to save metadata', 'error')
        }
    }

    const displayActivityEvents = externalActivityEvents !== null ? externalActivityEvents : activityEvents
    const displayActivityLoading = externalActivityEvents !== null ? externalActivityLoading : activityLoading

    const downloadActivityTypes = useMemo(
        () =>
            new Set([
                'asset.download.created',
                'asset.download.completed',
                'asset.download.failed',
            ]),
        [],
    )

    const downloadHistoryEvents = useMemo(() => {
        const events = displayActivityEvents || []
        return events.filter((e) => downloadActivityTypes.has(e.event_type))
    }, [displayActivityEvents, downloadActivityTypes])

    const showDownloadHistorySection =
        showDownloadHistory && !displayActivityLoading && downloadHistoryEvents.length > 0

    const isEligibleForDownload = Boolean(asset && asset.is_published !== false && !asset.archived_at)
    const canSingleAssetDownload = Boolean(isEligibleForDownload && !policyDisableSingleAsset)

    const isInDownloadBasket = useMemo(() => {
        if (!asset?.id) return false
        if (selection?.isSelected?.(asset.id)) return true
        const ids = bucket?.bucketAssetIds ?? []
        return ids.some((x) => String(x) === String(asset.id))
    }, [asset?.id, bucket?.bucketAssetIds, selection?.isSelected, selection?.selectedCount])

    const showDownloadBasketToggle = Boolean(selection || bucket)

    const handleQuickDownload = useCallback(async () => {
        if (!canSingleAssetDownload || !asset?.id || quickDownloadLoading) return
        setQuickDownloadLoading(true)
        try {
            const url =
                typeof route !== 'undefined' ? route('assets.download.single', { asset: asset.id }) : `/app/assets/${asset.id}/download`
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content
            onToast?.('Preparing download…', 'success')
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf || '',
                },
                credentials: 'same-origin',
            })
            const data = await res.json().catch(() => ({}))
            if (!res.ok) {
                onToast?.(data?.message || 'Download failed', 'error')
                return
            }
            const fileUrl = resolveTrackedSingleAssetFileUrl(data)
            const fallbackName =
                (typeof asset?.original_filename === 'string' && asset.original_filename.trim()) ||
                (typeof asset?.title === 'string' && asset.title.trim()) ||
                'download'
            if (fileUrl) {
                try {
                    await saveUrlAsDownload(fileUrl, fallbackName)
                    onToast?.('Download started', 'success')
                } catch {
                    onToast?.('Download failed', 'error')
                }
            } else {
                onToast?.('Download started', 'success')
            }
        } catch {
            onToast?.('Download failed', 'error')
        } finally {
            setQuickDownloadLoading(false)
        }
    }, [asset?.id, canSingleAssetDownload, quickDownloadLoading, onToast])

    const handleToggleDownloadBasket = useCallback(async () => {
        if (!asset?.id || bucketToggleLoading || !isEligibleForDownload) return
        if (!selection && !bucket) return
        setBucketToggleLoading(true)
        try {
            if (selection) {
                selection.toggleItem({
                    id: asset.id,
                    type: 'asset',
                    name: asset.title ?? asset.original_filename ?? '',
                    thumbnail_url:
                        asset.final_thumbnail_url ?? asset.thumbnail_url ?? asset.preview_thumbnail_url ?? null,
                    category_id: asset.metadata?.category_id ?? asset.category_id ?? null,
                })
            } else if (bucket) {
                if (isInDownloadBasket) {
                    await bucket.bucketRemove(asset.id)
                } else {
                    await bucket.bucketAdd(asset.id)
                }
            }
        } catch {
            onToast?.('Could not update download list', 'error')
        } finally {
            setBucketToggleLoading(false)
        }
    }, [
        asset?.id,
        asset?.title,
        asset?.original_filename,
        asset?.final_thumbnail_url,
        asset?.thumbnail_url,
        asset?.preview_thumbnail_url,
        asset?.metadata?.category_id,
        asset?.category_id,
        bucket,
        bucketToggleLoading,
        isEligibleForDownload,
        isInDownloadBasket,
        onToast,
        selection,
    ])

    if (!isOpen) return null
    if (!canViewAsset) return null

    const panelWidth = fullPage ? '100%' : '92vw'

    const handleRequestClose = () => {
        if (embeddedInLightbox) {
            onClose?.()
            return
        }
        if (fullPage) {
            onClose?.()
        } else {
            setIsExiting(true)
        }
    }

    const panelSlideClass = hasEntered && !isExiting ? 'translate-x-0' : 'translate-x-full'
    const backdropOpacityClass = hasEntered && !isExiting ? 'opacity-100' : 'opacity-0'
    const backdropPointerClass = isExiting ? 'pointer-events-none' : ''

    const panelOuterClass = embeddedInLightbox
        ? 'relative h-full w-full min-h-0 flex flex-col bg-neutral-950 text-neutral-100 shadow-none z-10'
        : `fixed top-0 right-0 h-full bg-white shadow-2xl z-50 flex flex-col transition-transform duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] ${panelSlideClass}`

    const lb = embeddedInLightbox
    /** Lightbox: avoid brand purple — neutral interaction color */
    const lbAccent = '#e5e5e5'
    const cardClass = lb
        ? 'bg-neutral-900/85 border border-neutral-800 rounded-lg p-5 mb-4'
        : 'bg-white border border-gray-200 rounded-lg p-6 mb-6'
    /** Lightbox: no heavy section dividers; CollapsibleSection titleInCard supplies the card */
    const sectionClass = lb ? 'mb-0' : 'border-t border-gray-200 mb-6'
    const dtClass = lb ? 'font-semibold text-neutral-400' : 'font-semibold text-gray-700'
    const ddClass = lb ? 'text-xs text-neutral-200' : 'text-sm text-gray-900'
    const collapsibleVariant = lb ? 'dark' : 'default'
    const metaTabListClass = lb ? 'flex gap-1 border-b border-neutral-800/50 mb-3' : 'flex gap-1 border-b border-gray-200 mb-4'
    const metaTabInactive = lb ? 'border-transparent text-neutral-500 hover:text-neutral-200' : 'border-transparent text-gray-500 hover:text-gray-800'
    const metaMuted = lb ? 'text-neutral-400' : 'text-gray-500'
    /** Fullscreen lightbox column: keep accordions closed until the user expands (quick view). */
    const detailSectionDefaultExpanded = embeddedInLightbox ? false : true
    const metaFieldInputClass = lb
        ? 'mt-1 block w-full max-w-xs rounded-md border-neutral-600 bg-neutral-900 text-neutral-100 text-sm shadow-sm focus:border-neutral-500 focus:outline-none focus:ring-1 focus:ring-neutral-500 [color-scheme:dark]'
        : 'mt-1 block w-full max-w-xs rounded-md border-gray-300 bg-white text-gray-900 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500'

    const versionThumbnailSrc = (v) => (v?.thumbnail_url ? String(v.thumbnail_url) : null)

    const titleField = metadata?.fields?.find((f) => (f.key || f.field_key) === 'title')
    const canEditAssetTitle = Boolean(canEditMetadata && titleField)

    const saveAssetTitle = async () => {
        const tf = metadata?.fields?.find((f) => (f.key || f.field_key) === 'title')
        if (!tf || !asset?.id) {
            setEditingTitle(false)

            return
        }
        const fid = tf.metadata_field_id ?? tf.field_id
        const val = titleEditValue.trim()
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content
        try {
            await fetch(`/app/assets/${asset.id}/metadata/edit`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf || '' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    metadata_field_id: fid,
                    value: val || (asset?.original_filename ? asset.original_filename.replace(/\.[^.]+$/, '') : ''),
                }),
            })
            router.reload({ preserveState: true, preserveScroll: true })
        } catch (e) {
            console.error('Failed to save title', e)
        }
        setEditingTitle(false)
    }

    const renderAssetNameOverview = (isLightbox) => {
        const showEdit = !readonlyMode && canEditAssetTitle && metadata
        const labelClass = isLightbox ? 'sr-only' : dtClass
        const nameDdClass = isLightbox ? `${ddClass} sm:col-span-2` : `${ddClass} min-w-0 sm:col-span-2`
        const nameTextClass = isLightbox
            ? 'text-sm font-semibold text-neutral-100 break-words'
            : 'font-medium text-gray-900 break-words'
        const editBtnClass = isLightbox
            ? 'inline-flex items-center gap-1 text-xs text-neutral-500 hover:text-neutral-300 shrink-0'
            : 'inline-flex items-center gap-1 text-xs text-gray-500 hover:text-gray-700 shrink-0'
        const inputClass = isLightbox
            ? 'text-sm font-medium border rounded px-2 py-1.5 w-full focus:ring-2 focus:ring-offset-1 border-neutral-600 bg-neutral-900 text-neutral-100 focus:border-neutral-500'
            : 'text-sm font-medium border rounded px-2 py-1.5 w-full max-w-full focus:ring-2 focus:ring-offset-1 border-gray-300 text-gray-900 focus:border-gray-400'

        return (
            <>
                <dt className={labelClass}>Asset name</dt>
                <dd className={nameDdClass}>
                    {editingTitle && showEdit ? (
                        <input
                            ref={titleInputRef}
                            type="text"
                            value={titleEditValue}
                            onChange={(e) => setTitleEditValue(e.target.value)}
                            onBlur={saveAssetTitle}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.currentTarget.blur()
                                }
                            }}
                            className={inputClass}
                            id="asset-detail-panel-title"
                            aria-label="Edit asset name"
                        />
                    ) : (
                        <div className="flex flex-wrap items-start gap-x-2 gap-y-1">
                            <span
                                id="asset-detail-panel-title"
                                className={nameTextClass}
                            >
                                {asset?.title || asset?.original_filename || 'Asset'}
                            </span>
                            {showEdit && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setTitleEditValue(asset?.title || asset?.original_filename || '')
                                        setEditingTitle(true)
                                        setTimeout(() => titleInputRef.current?.focus(), 0)
                                    }}
                                    className={editBtnClass}
                                    aria-label="Edit asset name"
                                >
                                    <PencilIcon className="h-3.5 w-3.5" />
                                    Edit
                                </button>
                            )}
                        </div>
                    )}
                </dd>
            </>
        )
    }

    return (
        <>
            {!fullPage && !embeddedInLightbox && (
                <div
                    className={`fixed inset-0 bg-black/30 z-40 transition-opacity duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] ${backdropOpacityClass} ${backdropPointerClass}`}
                    aria-hidden
                    onClick={handleRequestClose}
                />
            )}
            <div
                className={panelOuterClass}
                style={embeddedInLightbox ? undefined : { width: panelWidth, maxWidth: fullPage ? '100%' : '1240px' }}
                role="dialog"
                aria-labelledby="asset-detail-panel-title"
                onClick={embeddedInLightbox ? (e) => e.stopPropagation() : undefined}
            >
                {/* Sticky Header */}
                <header
                    className={`sticky top-0 z-10 flex-shrink-0 border-b ${
                        lb ? 'border-transparent bg-neutral-950 pb-4' : 'border-gray-200 bg-white pb-3 mb-4'
                    }`}
                    onClick={lb ? (e) => e.stopPropagation() : undefined}
                >
                    <div className={lb ? 'space-y-4 px-5 py-4' : 'space-y-3 p-4'}>
                        {/* Filename in header — hidden in lightbox (still editable under File information) */}
                        {!lb && (
                        <div className="flex items-center gap-2">
                            {editingFilename ? (
                                <div className="flex items-center gap-2 flex-1 min-w-0">
                                    <input
                                        type="text"
                                        value={filenameEditValue}
                                        onChange={(e) => setFilenameEditValue(e.target.value)}
                                        className={`text-sm border rounded px-2 py-1 flex-1 max-w-sm focus:ring-2 focus:ring-offset-1 ${
                                            lb
                                                ? 'border-neutral-600 bg-neutral-900 text-neutral-100 focus:border-neutral-500'
                                                : 'text-gray-600 border-gray-300'
                                        }`}
                                        placeholder="Filename"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => { setEditingFilename(false); setFilenameEditValue(asset?.original_filename || ''); }}
                                        className={
                                            lb
                                                ? 'text-xs font-medium rounded px-2 py-1 bg-neutral-200 text-neutral-900 hover:bg-white'
                                                : 'text-xs font-medium text-white rounded px-2 py-1'
                                        }
                                        style={!lb ? { backgroundColor: brandPrimary } : undefined}
                                    >
                                        Save
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => { setFilenameEditValue(asset?.original_filename || ''); setEditingFilename(false); }}
                                        className={`text-xs font-medium ${lb ? 'text-neutral-400 hover:text-neutral-200' : 'text-gray-600 hover:text-gray-900'}`}
                                    >
                                        Cancel
                                    </button>
                                </div>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => { setFilenameEditValue(asset?.original_filename || ''); setEditingFilename(true); }}
                                    className={`text-sm truncate max-w-md text-left ${lb ? 'text-neutral-400 hover:text-neutral-200' : 'text-gray-500 hover:text-gray-700'}`}
                                >
                                    {asset?.original_filename || '—'}
                                </button>
                            )}
                        </div>
                        )}
                        {/* Badges: Category + Lifecycle only */}
                        <div className="flex flex-wrap items-center gap-2">
                            {metadata?.category && (
                                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${lb ? 'bg-neutral-800 text-neutral-200' : 'bg-gray-100 text-gray-800'}`}>
                                    {metadata.category.name}
                                </span>
                            )}
                            {asset?.archived_at && (
                                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium border ${lb ? 'bg-neutral-900 text-neutral-300 border-neutral-600' : 'bg-gray-100 text-gray-700 border-gray-300'}`}>
                                    Archived
                                </span>
                            )}
                            {asset?.is_published === true && !asset?.archived_at && (
                                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium border ${lb ? 'bg-emerald-900/50 text-emerald-200 border-emerald-700/50' : 'bg-green-100 text-green-700 border-green-300'}`}>
                                    Published
                                </span>
                            )}
                            {asset?.is_published === false && !asset?.archived_at && (
                                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium border ${lb ? 'bg-amber-900/40 text-amber-100 border-amber-700/40' : 'bg-yellow-100 text-yellow-700 border-yellow-300'}`}>
                                    Unpublished
                                </span>
                            )}
                        </div>
                        {!lb && (
                            <>
                            {/* Preview: hidden in lightbox embed — main stage already shows the asset */}
                            <div className="relative rounded-lg bg-gray-100 border border-gray-200 overflow-hidden flex items-center justify-center" style={{ maxHeight: previewExpanded ? '70vh' : '360px', minHeight: '200px' }}>
                            {asset?.id && (
                                <ThumbnailPreview
                                    asset={asset}
                                    alt={asset?.title || asset?.original_filename || 'Preview'}
                                    className="w-full h-full object-contain"
                                    size="lg"
                                    preferLargeForVector
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
                        {(asset?.preview_unavailable_user_message || asset?.metadata?.preview_unavailable_user_message) && (
                            <p
                                className="mt-2 rounded-lg border border-amber-200/80 bg-amber-50 px-3 py-2 text-sm text-amber-950"
                                role="status"
                            >
                                {asset.preview_unavailable_user_message || asset.metadata?.preview_unavailable_user_message}
                            </p>
                        )}
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
                            </>
                        )}
                    </div>
                </header>

                {/* Scrollable body — min-h-0 required so flex child can shrink and scroll (Chrome/Safari) */}
                <div
                    className={`flex-1 min-h-0 overflow-y-auto pb-[calc(env(safe-area-inset-bottom)+5rem)] md:pb-4 ${lb ? 'bg-neutral-950 text-xs text-neutral-100 [color-scheme:dark]' : ''}`}
                >
                    <div className={lb ? 'space-y-1 px-4 py-4' : 'divide-y divide-gray-200 p-4 sm:p-5'}>
                        {loading && (
                            <div className={`py-8 text-center text-sm ${lb ? 'text-neutral-500' : 'text-gray-500'}`}>
                                Loading metadata…
                            </div>
                        )}
                        {error && (
                            <div className={lb ? 'border-b border-red-900/60 bg-red-950/40 p-4' : 'border-b border-red-100 bg-red-50 p-4'}>
                                <p className={`text-sm ${lb ? 'text-red-200' : 'text-red-800'}`}>{error}</p>
                            </div>
                        )}

                        {readonlyMode && embeddedInLightbox && (
                            <div className="mb-3 rounded-lg border border-neutral-800 bg-neutral-900/70 px-4 py-3">
                                <div className="flex items-start justify-between gap-3">
                                    <h2 className="min-w-0 flex-1 text-base font-semibold leading-snug text-neutral-100">
                                        {asset?.title || asset?.original_filename || 'Untitled asset'}
                                    </h2>
                                    <button
                                        type="button"
                                        onClick={handleRequestClose}
                                        className="flex-shrink-0 rounded-md p-1.5 text-neutral-400 transition hover:bg-neutral-800 hover:text-neutral-100"
                                        aria-label="Close fullscreen view"
                                    >
                                        <XMarkIcon className="h-5 w-5" />
                                    </button>
                                </div>
                                <p className="mt-2 text-xs text-neutral-400">
                                    {typeof onManageInDrawer === 'function'
                                        ? 'Manage this asset in the sidebar to edit or run processing.'
                                        : 'View only — open the asset from the library to edit or run processing.'}
                                </p>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        disabled={!canSingleAssetDownload || quickDownloadLoading}
                                        onClick={() => handleQuickDownload()}
                                        className={`inline-flex flex-1 min-w-[8rem] items-center justify-center gap-2 rounded-md border px-3 py-2 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${
                                            canSingleAssetDownload
                                                ? 'border-white/70 bg-transparent text-white shadow-none hover:bg-white/10 focus-visible:outline-white'
                                                : 'cursor-not-allowed border-neutral-700 bg-neutral-900 text-neutral-500'
                                        }`}
                                        title={
                                            !isEligibleForDownload
                                                ? 'Publish this asset to download'
                                                : policyDisableSingleAsset
                                                  ? 'Your organization requires downloads to be packaged.'
                                                  : 'Download this asset'
                                        }
                                    >
                                        <ArrowDownTrayIcon className="h-4 w-4 shrink-0" aria-hidden />
                                        {quickDownloadLoading ? 'Preparing…' : 'Download'}
                                    </button>
                                    {showDownloadBasketToggle && (
                                        <button
                                            type="button"
                                            disabled={!isEligibleForDownload || bucketToggleLoading}
                                            onClick={() => handleToggleDownloadBucket()}
                                            className={`inline-flex flex-1 min-w-[8rem] items-center justify-center gap-2 rounded-md border px-3 py-2 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${
                                                isInDownloadBasket
                                                    ? 'border-emerald-400/80 bg-transparent text-emerald-100 shadow-none hover:bg-emerald-500/15 focus-visible:outline-emerald-300'
                                                    : isEligibleForDownload
                                                      ? 'border-white/70 bg-transparent text-white shadow-none hover:bg-white/10 focus-visible:outline-white'
                                                      : 'cursor-not-allowed border-neutral-800 bg-neutral-950 text-neutral-600'
                                            }`}
                                            title={
                                                !isEligibleForDownload
                                                    ? 'Publish this asset to add to download'
                                                    : isInDownloadBasket
                                                      ? 'Remove from download list'
                                                      : 'Add to download list'
                                            }
                                        >
                                            {isInDownloadBasket ? (
                                                <>
                                                    <CheckIcon className="h-4 w-4 shrink-0" aria-hidden />
                                                    {bucketToggleLoading ? '…' : 'In download'}
                                                </>
                                            ) : (
                                                <>
                                                    <RectangleStackIcon className="h-4 w-4 shrink-0" aria-hidden />
                                                    {bucketToggleLoading ? '…' : 'Add to download'}
                                                </>
                                            )}
                                        </button>
                                    )}
                                </div>
                                {typeof onManageInDrawer === 'function' && (
                                    <button
                                        type="button"
                                        onClick={() => onManageInDrawer()}
                                        className="mt-3 text-sm font-medium text-blue-400 hover:text-blue-300"
                                    >
                                        Manage asset →
                                    </button>
                                )}
                            </div>
                        )}

                        {/* Section 1 — Overview (expanded by default) */}
                        <section className={sectionClass} aria-labelledby="section-overview">
                            <CollapsibleSection
                                variant={collapsibleVariant}
                                titleInCard={lb}
                                title="Overview"
                                defaultExpanded={detailSectionDefaultExpanded}
                            >
                                {lb ? (
                                    <dl className="grid grid-cols-1 gap-y-2.5 sm:grid-cols-2 sm:gap-x-4">
                                        {renderAssetNameOverview(true)}
                                        {asset?.created_at && (
                                            <>
                                                <dt className="sr-only">Created at</dt>
                                                <dd className={ddClass} title="Created at">
                                                    {new Date(asset.created_at).toLocaleString()}
                                                </dd>
                                            </>
                                        )}
                                        {asset?.created_by && (
                                            <>
                                                <dt className="sr-only">Created by</dt>
                                                <dd className={ddClass} title="Created by">
                                                    {asset.created_by.name ||
                                                        [asset.created_by.first_name, asset.created_by.last_name].filter(Boolean).join(' ') ||
                                                        '—'}
                                                </dd>
                                            </>
                                        )}
                                        {asset?.updated_at && (
                                            <>
                                                <dt className="sr-only">Last modified</dt>
                                                <dd className={ddClass} title="Last modified">
                                                    {new Date(asset.updated_at).toLocaleString()}
                                                </dd>
                                            </>
                                        )}
                                        {auth?.approval_features?.approvals_enabled && asset?.approved_at && (
                                            <>
                                                <dt className="sr-only">Approved at</dt>
                                                <dd className={ddClass} title="Approved at">
                                                    {new Date(asset.approved_at).toLocaleString()}
                                                </dd>
                                            </>
                                        )}
                                        {auth?.approval_features?.approvals_enabled && asset?.approved_by && (
                                            <>
                                                <dt className="sr-only">Approved by</dt>
                                                <dd className={ddClass} title="Approved by">
                                                    {asset.approved_by.name || '—'}
                                                </dd>
                                            </>
                                        )}
                                        <dt className="sr-only">Lifecycle</dt>
                                        <dd className={ddClass} title="Lifecycle">
                                            {asset?.archived_at ? 'Archived' : asset?.is_published ? 'Published' : 'Unpublished'}
                                        </dd>
                                    </dl>
                                ) : (
                                    <div className={cardClass}>
                                        <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                            {renderAssetNameOverview(false)}
                                            {asset?.created_at && (
                                                <>
                                                    <dt className={dtClass}>Created at</dt>
                                                    <dd className={ddClass}>{new Date(asset.created_at).toLocaleString()}</dd>
                                                </>
                                            )}
                                            {asset?.created_by && (
                                                <>
                                                    <dt className={dtClass}>Created by</dt>
                                                    <dd className={ddClass}>
                                                        {asset.created_by.name ||
                                                            [asset.created_by.first_name, asset.created_by.last_name].filter(Boolean).join(' ') ||
                                                            '—'}
                                                    </dd>
                                                </>
                                            )}
                                            {asset?.updated_at && (
                                                <>
                                                    <dt className={dtClass}>Last modified</dt>
                                                    <dd className={ddClass}>{new Date(asset.updated_at).toLocaleString()}</dd>
                                                </>
                                            )}
                                            {auth?.approval_features?.approvals_enabled && asset?.approved_at && (
                                                <>
                                                    <dt className={dtClass}>Approved at</dt>
                                                    <dd className={ddClass}>{new Date(asset.approved_at).toLocaleString()}</dd>
                                                </>
                                            )}
                                            {auth?.approval_features?.approvals_enabled && asset?.approved_by && (
                                                <>
                                                    <dt className={dtClass}>Approved by</dt>
                                                    <dd className={ddClass}>{asset.approved_by.name || '—'}</dd>
                                                </>
                                            )}
                                            <dt className={dtClass}>Lifecycle</dt>
                                            <dd className={ddClass}>
                                                {asset?.archived_at ? 'Archived' : asset?.is_published ? 'Published' : 'Unpublished'}
                                            </dd>
                                        </dl>
                                    </div>
                                )}
                            </CollapsibleSection>
                        </section>

                        {/* Section 2 — Metadata (grouped, section-based edit; expanded by default) */}
                        {!loading && !error && metadata && (
                            <section className={sectionClass} aria-labelledby="section-metadata">
                                <CollapsibleSection variant={collapsibleVariant} titleInCard={lb} title="Fields" defaultExpanded={detailSectionDefaultExpanded}>
                                <div className={metaTabListClass} role="tablist" aria-label="Metadata sections">
                                    <button
                                        type="button"
                                        role="tab"
                                        aria-selected={detailMetadataTab === 'fields'}
                                        onClick={() => setDetailMetadataTab('fields')}
                                        className={`px-3 py-2 font-medium rounded-t-md border-b-2 -mb-px transition-colors ${lb ? 'text-xs' : 'text-sm'} ${
                                            detailMetadataTab === 'fields'
                                                ? lb
                                                    ? 'border-neutral-200 text-neutral-100'
                                                    : 'border-transparent'
                                                : metaTabInactive
                                        }`}
                                        style={
                                            detailMetadataTab === 'fields' && !lb
                                                ? { borderBottomColor: brandPrimary, color: brandPrimary }
                                                : undefined
                                        }
                                    >
                                        Metadata
                                    </button>
                                    <button
                                        type="button"
                                        role="tab"
                                        aria-selected={detailMetadataTab === 'embedded'}
                                        onClick={() => setDetailMetadataTab('embedded')}
                                        className={`px-3 py-2 font-medium rounded-t-md border-b-2 -mb-px transition-colors ${lb ? 'text-xs' : 'text-sm'} ${
                                            detailMetadataTab === 'embedded'
                                                ? lb
                                                    ? 'border-neutral-200 text-neutral-100'
                                                    : 'border-transparent'
                                                : metaTabInactive
                                        }`}
                                        style={
                                            detailMetadataTab === 'embedded' && !lb
                                                ? { borderBottomColor: brandPrimary, color: brandPrimary }
                                                : undefined
                                        }
                                    >
                                        Embedded metadata
                                    </button>
                                </div>

                                {detailMetadataTab === 'embedded' && (
                                    <div className="mb-6">
                                        <AssetEmbeddedMetadataPanel embeddedMetadata={metadata.embedded_metadata} variant={lb ? 'dark' : 'default'} />
                                    </div>
                                )}

                                {detailMetadataTab === 'fields' && (
                                <div className={lb ? 'space-y-3' : cardClass}>
                                {metadataByGroup.map(({ key: groupKey, fields }) => {
                                    const isEditing = metadataEditGroup === groupKey
                                    const dirty = metadataDirty[groupKey]
                                    const canEdit =
                                        !readonlyMode &&
                                        canEditMetadata &&
                                        fields.some((f) => !f.readonly && f.population_mode !== 'automatic')
                                    return (
                                        <div
                                            key={groupKey}
                                            className={`mb-5 last:mb-0 rounded-lg border transition-colors ${
                                                isEditing
                                                    ? lb
                                                        ? 'bg-neutral-900/80 border-neutral-700 shadow-sm'
                                                        : 'bg-gray-50 border-gray-200 shadow-sm'
                                                    : lb
                                                      ? 'border-neutral-800/80'
                                                      : 'border-transparent'
                                            }`}
                                        >
                                            <div className="flex items-center justify-between px-3 pt-3 pb-2 gap-2">
                                                {!lb ? (
                                                    <h4 className="text-sm font-semibold flex items-center gap-2 text-gray-800">
                                                        {groupLabel(groupKey)}
                                                        {dirty && Object.keys(dirty).length > 0 && (
                                                            <span className="text-xs font-normal text-amber-600">Unsaved changes</span>
                                                        )}
                                                    </h4>
                                                ) : (
                                                    <h4 className="text-xs font-semibold uppercase tracking-wide text-neutral-500">
                                                        {groupLabel(groupKey)}
                                                        {dirty && Object.keys(dirty).length > 0 && (
                                                            <span className="ml-2 font-normal normal-case tracking-normal text-amber-400">· Unsaved</span>
                                                        )}
                                                    </h4>
                                                )}
                                                <div className={`flex items-center gap-2 ${lb ? 'ml-auto' : ''}`}>
                                                    {lb && dirty && Object.keys(dirty).length > 0 && (
                                                        <span className="sr-only">Unsaved changes</span>
                                                    )}
                                                    {canEdit && !isEditing && (
                                                        <button
                                                            type="button"
                                                            onClick={() => setMetadataEditGroup(groupKey)}
                                                            className={`text-xs font-medium rounded px-2 py-1 focus:outline-none focus:ring-2 focus:ring-offset-1 ${
                                                                lb
                                                                    ? 'text-neutral-200 hover:text-white hover:underline decoration-neutral-600 underline-offset-2'
                                                                    : ''
                                                            }`}
                                                            style={!lb ? { color: brandPrimary } : undefined}
                                                        >
                                                            Edit
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                            <dl className="px-3 pb-3">
                                                {fields.map((field) => {
                                                    const metadataFieldId = field.metadata_field_id ?? field.field_id
                                                    const isEditableField =
                                                        !readonlyMode &&
                                                        canEditMetadata &&
                                                        !field.readonly &&
                                                        field.population_mode !== 'automatic'
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
                                                            role={!isSystemField && !isEditing && canEdit && isEditableField ? 'button' : undefined}
                                                            tabIndex={!isSystemField && !isEditing && canEdit && isEditableField ? 0 : undefined}
                                                            aria-label={
                                                                !isSystemField && !isEditing && canEdit && isEditableField
                                                                    ? `Edit ${field.display_label}`
                                                                    : undefined
                                                            }
                                                            onClick={(e) => {
                                                                if (isEditing || isSystemField || !canEdit || !isEditableField) return
                                                                if (e.target.closest('button, a, input, select, textarea, [role="slider"]')) return
                                                                setMetadataEditGroup(groupKey)
                                                            }}
                                                            onKeyDown={(e) => {
                                                                if (e.key !== 'Enter' && e.key !== ' ') return
                                                                if (isEditing || isSystemField || !canEdit || !isEditableField) return
                                                                e.preventDefault()
                                                                setMetadataEditGroup(groupKey)
                                                            }}
                                                            className={`flex items-start justify-between gap-2 ${
                                                                lb ? 'py-2' : 'border-b last:border-b-0 border-gray-100 py-3'
                                                            } ${
                                                                !isSystemField && isEditableField && !isEditing
                                                                    ? `group cursor-pointer rounded-md px-2 -mx-2 transition ${lb ? 'hover:bg-neutral-800/60' : 'hover:bg-gray-50'}`
                                                                    : ''
                                                            }`}
                                                            title={
                                                                isSystemField
                                                                    ? 'Automatically generated. Cannot be edited.'
                                                                    : lb
                                                                      ? field.display_label
                                                                      : undefined
                                                            }
                                                        >
                                                            <div className="min-w-0 flex-1">
                                                                {lb ? (
                                                                    <span className="mb-1 block text-[11px] font-medium uppercase tracking-wide text-neutral-500">
                                                                        {field.display_label}
                                                                    </span>
                                                                ) : (
                                                                    <span className="text-sm font-semibold text-gray-700">{field.display_label}</span>
                                                                )}
                                                                {isEditing && isEditableField ? (
                                                                    <div className="mt-1">
                                                                        {isRating ? (
                                                                            <StarRating
                                                                                value={Number(editValue) || 0}
                                                                                onChange={(v) => setDirtyValue(v)}
                                                                                editable
                                                                                maxStars={5}
                                                                                size="md"
                                                                                primaryColor={lb ? lbAccent : brandPrimary}
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
                                                                                        className={`relative w-11 h-6 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-focus:outline-none peer-focus:ring-4 ${lb ? 'bg-neutral-700' : 'bg-gray-200'}`}
                                                                                        style={{
                                                                                            ['--tw-ring-color']: lb ? '#a3a3a3' : brandPrimary,
                                                                                            ...(editValue === true || editValue === 'true'
                                                                                                ? { backgroundColor: lb ? lbAccent : brandPrimary }
                                                                                                : {}),
                                                                                        }}
                                                                                    />
                                                                                </div>
                                                                                <span className={`text-sm ${lb ? 'text-neutral-200' : 'text-gray-700'}`}>{editValue === true || editValue === 'true' ? 'Yes' : 'No'}</span>
                                                                            </label>
                                                                        ) : field.type === 'boolean' ? (
                                                                            <label className="flex items-center gap-2">
                                                                                <input
                                                                                    type="checkbox"
                                                                                    checked={!!editValue}
                                                                                    onChange={(e) => setDirtyValue(e.target.checked)}
                                                                                    className={`rounded ${lb ? 'border-neutral-600 bg-neutral-900' : 'border-gray-300'}`}
                                                                                />
                                                                                <span className={`text-sm ${lb ? 'text-neutral-200' : 'text-gray-700'}`}>{editValue ? 'Yes' : 'No'}</span>
                                                                            </label>
                                                                        ) : field.type === 'select' && Array.isArray(field.options) ? (
                                                                            <select
                                                                                value={editValue ?? ''}
                                                                                onChange={(e) => setDirtyValue(e.target.value)}
                                                                                className={metaFieldInputClass}
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
                                                                                className={metaFieldInputClass}
                                                                            />
                                                                        ) : field.type === 'date' ? (
                                                                            <input
                                                                                type="date"
                                                                                value={editValue ? (typeof editValue === 'string' ? editValue.slice(0, 10) : new Date(editValue).toISOString().slice(0, 10)) : ''}
                                                                                onChange={(e) => setDirtyValue(e.target.value || null)}
                                                                                className={metaFieldInputClass}
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
                                                                                className={metaFieldInputClass}
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
                                                                            primaryColor={lb ? lbAccent : brandPrimary}
                                                                        />
                                                                    </span>
                                                                ) : isToggleBoolean ? (
                                                                    <span className={`text-sm mt-1 ${lb ? 'text-neutral-100' : 'text-gray-900'}`}>
                                                                        {field.current_value === true || field.current_value === 'true' ? 'Yes' : 'No'}
                                                                    </span>
                                                                ) : (displayValue || dominantColorsArray) ? (
                                                                    lb ? (
                                                                        <span className={isSystemField ? 'text-xs text-neutral-300' : 'text-xs text-neutral-100'}>
                                                                            {dominantColorsArray ? (
                                                                                <DominantColorsSwatches dominantColors={dominantColorsArray} />
                                                                            ) : (
                                                                                displayValue
                                                                            )}
                                                                        </span>
                                                                    ) : (
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
                                                                    )
                                                                ) : null}
                                                            </div>
                                                            <div className="flex-shrink-0 flex items-center gap-2">
                                                                {isSystemField ? (
                                                                    <>
                                                                        <LockClosedIcon className={`h-3.5 w-3.5 ${lb ? 'text-neutral-500' : 'text-gray-400'}`} aria-hidden />
                                                                        <span className={`text-xs px-2 py-0.5 rounded-full ${lb ? 'bg-neutral-800 text-neutral-300' : 'bg-gray-100 text-gray-600'}`}>System</span>
                                                                    </>
                                                                ) : (
                                                                    <>
                                                                        {getSourceBadge(field)}
                                                                        {canEditMetadata && !isEditing && isEditableField && (
                                                                            <span className="opacity-0 group-hover:opacity-100 transition" aria-hidden>
                                                                                <PencilIcon className={`h-3.5 w-3.5 ${lb ? 'text-neutral-500' : 'text-gray-400'}`} />
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
                                                <div
                                                    className={`flex items-start justify-between gap-2 py-3 px-3 ${
                                                        lb ? 'flex-col gap-1' : ''
                                                    } ${lb ? '' : 'border-b last:border-b-0 border-gray-100'}`}
                                                >
                                                    <span
                                                        className={
                                                            lb
                                                                ? 'text-[11px] font-medium uppercase tracking-wide text-neutral-500'
                                                                : 'text-sm font-semibold text-gray-700'
                                                        }
                                                    >
                                                        Collection
                                                    </span>
                                                    <div className="min-w-0 flex-1 flex justify-end">
                                                        {isEditing && canEdit ? (
                                                            <div className="w-full max-w-sm">
                                                                {dropdownCollectionsLoading ? (
                                                                    <p className={`text-sm ${metaMuted}`}>Loading collections…</p>
                                                                ) : (
                                                                    <CollectionSelector
                                                                        variant={lb ? 'dark' : 'light'}
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
                                                            <span className={`text-sm ${lb ? 'text-neutral-100' : 'text-gray-900'}`} title={lb ? 'Collection' : undefined}>
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
                                                <div className={`sticky bottom-0 left-0 right-0 flex items-center justify-end gap-2 px-3 py-3 mt-2 rounded-b-lg border-t ${lb ? 'bg-neutral-900/90 border-neutral-700' : 'bg-gray-50 border-gray-200'}`}>
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
                                                        className={`text-sm font-medium px-3 py-1.5 ${lb ? 'text-neutral-300 hover:text-white' : 'text-gray-600 hover:text-gray-900'}`}
                                                    >
                                                        Cancel
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => saveMetadataGroup(groupKey)}
                                                        disabled={!dirty || Object.keys(dirty).length === 0}
                                                        className={
                                                            lb
                                                                ? 'text-sm font-medium rounded-md px-3 py-1.5 bg-neutral-200 text-neutral-900 hover:bg-white disabled:opacity-50 disabled:cursor-not-allowed'
                                                                : 'text-sm font-medium text-white rounded-md px-3 py-1.5 disabled:opacity-50 disabled:cursor-not-allowed'
                                                        }
                                                        style={!lb ? { backgroundColor: brandPrimary } : undefined}
                                                    >
                                                        Save
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    )
                                })}
                                </div>
                                )}
                                </CollapsibleSection>
                            </section>
                        )}

                        {/* Section 3 — File Information (at least quick-view parity: status + tooltip, publish/who, filename editable) */}
                        <section className={lb ? 'mb-0 !mt-0' : sectionClass} aria-labelledby="section-file">
                            <CollapsibleSection variant={collapsibleVariant} titleInCard={lb} title="File information" defaultExpanded={detailSectionDefaultExpanded}>
                                <div className={lb ? '' : cardClass}>
                                    <dl className={`grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3 ${lb ? 'gap-y-2.5' : 'text-sm'}`}>
                                        {/* Filename (editable when permission) */}
                                        <div className="sm:col-span-2">
                                            <dt className={lb ? 'sr-only' : 'font-semibold mb-1 text-gray-700'}>Filename</dt>
                                            <dd className={`${lb ? ddClass : 'text-sm text-gray-900'}`} title={lb ? 'Filename' : undefined}>
                                                {editingFilename ? (
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <input
                                                            type="text"
                                                            value={filenameEditValue}
                                                            onChange={(e) => setFilenameEditValue(e.target.value)}
                                                            className={`text-sm border rounded px-2 py-1 flex-1 min-w-0 max-w-md font-mono focus:ring-2 focus:ring-offset-1 ${
                                                                lb
                                                                    ? 'border-neutral-600 bg-neutral-900 text-neutral-100'
                                                                    : 'text-gray-800 border-gray-300'
                                                            }`}
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
                                                            className={
                                                                lb
                                                                    ? 'text-xs font-medium rounded px-2 py-1 bg-neutral-200 text-neutral-900 hover:bg-white'
                                                                    : 'text-xs font-medium text-white rounded px-2 py-1'
                                                            }
                                                            style={!lb ? { backgroundColor: brandPrimary } : undefined}
                                                        >
                                                            Save
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => { setFilenameEditValue(asset?.original_filename || ''); setEditingFilename(false) }}
                                                            className={`text-xs font-medium ${lb ? 'text-neutral-400 hover:text-neutral-200' : 'text-gray-600 hover:text-gray-900'}`}
                                                        >
                                                            Cancel
                                                        </button>
                                                    </div>
                                                ) : (
                                                    <span className="font-mono break-all">
                                                        {asset?.original_filename || '—'}
                                                        {canEditMetadata && !readonlyMode && (
                                                            <button
                                                                type="button"
                                                                onClick={() => { setFilenameEditValue(asset?.original_filename || ''); setEditingFilename(true) }}
                                                                className={`ml-2 inline-flex items-center gap-1 text-xs font-medium rounded focus:outline-none focus:ring-2 focus:ring-offset-1 ${
                                                                    lb
                                                                        ? 'text-neutral-200 hover:text-white hover:underline decoration-neutral-600 underline-offset-2'
                                                                        : ''
                                                                }`}
                                                                style={!lb ? { color: brandPrimary } : undefined}
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
                                        <dt className={lb ? 'sr-only' : dtClass}>Status</dt>
                                        <dd className={ddClass} title={lb ? 'Status' : undefined}>
                                            {(() => {
                                                const status = (asset?.thumbnail_status ?? asset?.status ?? '').toString().toLowerCase()
                                                const label = status === 'completed' ? 'Completed' : status === 'processing' ? 'Processing' : status === 'failed' ? 'Failed' : status === 'skipped' ? 'Skipped' : status === 'pending' ? 'Pending' : (asset?.thumbnail_status ?? asset?.status ?? '—')
                                                const tooltip = status === 'completed' ? 'Thumbnail and preview generation completed.' : status === 'processing' ? 'Thumbnail or preview is being generated.' : status === 'failed' ? (asset?.thumbnail_error ? `Thumbnail generation failed: ${asset.thumbnail_error}` : 'Thumbnail generation failed.') : status === 'skipped' ? (asset?.metadata?.thumbnail_skip_message || asset?.metadata?.thumbnail_skip_reason ? `Preview skipped: ${asset.metadata.thumbnail_skip_message || asset.metadata.thumbnail_skip_reason}` : 'Preview not generated for this file type.') : 'Thumbnail or preview is pending.'
                                                const pill =
                                                    status === 'completed'
                                                        ? lb
                                                            ? 'bg-emerald-900/50 text-emerald-200'
                                                            : 'bg-green-100 text-green-800'
                                                        : status === 'processing'
                                                          ? lb
                                                              ? 'bg-amber-900/50 text-amber-100'
                                                              : 'bg-amber-100 text-amber-800'
                                                          : status === 'failed'
                                                            ? lb
                                                                ? 'bg-red-950/60 text-red-200'
                                                                : 'bg-red-100 text-red-800'
                                                            : status === 'skipped'
                                                              ? lb
                                                                  ? 'bg-neutral-800 text-neutral-300'
                                                                  : 'bg-gray-100 text-gray-700'
                                                              : lb
                                                                ? 'bg-neutral-800 text-neutral-400'
                                                                : 'bg-gray-100 text-gray-600'
                                                return (
                                                    <span
                                                        title={tooltip}
                                                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${pill}`}
                                                    >
                                                        {label}
                                                    </span>
                                                )
                                            })()}
                                        </dd>
                                        {/* Category (grid sends asset.category; metadata may have category object) */}
                                        {(asset?.category?.name || metadata?.category?.name) && (
                                            <>
                                                <dt className={lb ? 'sr-only' : dtClass}>Category</dt>
                                                <dd className={ddClass} title={lb ? 'Category' : undefined}>
                                                    {asset?.category?.name || metadata?.category?.name}
                                                </dd>
                                            </>
                                        )}
                                        {/* Uploaded (created_at) */}
                                        {asset?.created_at && (
                                            <>
                                                <dt className={lb ? 'sr-only' : dtClass}>Uploaded</dt>
                                                <dd className={ddClass} title={lb ? 'Uploaded' : undefined}>
                                                    {new Date(asset.created_at).toLocaleString()}
                                                </dd>
                                            </>
                                        )}
                                        {/* Published (date + by) when not redundant with Overview lifecycle */}
                                        {asset?.published_at && (
                                            <>
                                                <dt className={lb ? 'sr-only' : dtClass}>Published</dt>
                                                <dd className={ddClass} title={lb ? 'Published' : undefined}>
                                                    {new Date(asset.published_at).toLocaleString()}
                                                    {asset.published_by && (
                                                        <span className={`ml-1 ${metaMuted}`}>
                                                            by {asset.published_by.name || [asset.published_by.first_name, asset.published_by.last_name].filter(Boolean).join(' ') || '—'}
                                                        </span>
                                                    )}
                                                </dd>
                                            </>
                                        )}
                                        {/* Archived (date + by) */}
                                        {asset?.archived_at && (
                                            <>
                                                <dt className={lb ? 'sr-only' : dtClass}>Archived</dt>
                                                <dd className={ddClass} title={lb ? 'Archived' : undefined}>
                                                    {new Date(asset.archived_at).toLocaleString()}
                                                    {asset.archived_by && (
                                                        <span className={`ml-1 ${metaMuted}`}>
                                                            by {asset.archived_by.name || [asset.archived_by.first_name, asset.archived_by.last_name].filter(Boolean).join(' ') || '—'}
                                                        </span>
                                                    )}
                                                </dd>
                                            </>
                                        )}
                                        <dt className={lb ? 'sr-only' : dtClass}>File type</dt>
                                        <dd className={ddClass} title={lb ? 'File type' : undefined}>
                                            {asset?.mime_type || '—'}
                                        </dd>
                                        <dt className={lb ? 'sr-only' : dtClass}>File size</dt>
                                        <dd className={ddClass} title={lb ? 'File size' : undefined}>
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
                                                <dt className={lb ? 'sr-only' : dtClass}>Dimensions</dt>
                                                <dd className={ddClass} title={lb ? 'Dimensions' : undefined}>
                                                    {asset?.width != null && asset?.height != null
                                                        ? `${asset.width} × ${asset.height}`
                                                        : `${asset.metadata?.image_width} × ${asset.metadata?.image_height}`}
                                                    {asset?.metadata?.dimensions && typeof asset.metadata.dimensions === 'string' && asset.metadata.dimensions.match(/px/i) && ` (${asset.metadata.dimensions})`}
                                                </dd>
                                            </>
                                        )}
                                        {allowsFocalPoint && !readonlyMode && canEditMetadata && (
                                            <>
                                                <dt className={lb ? 'sr-only' : dtClass}>Focal point</dt>
                                                <dd className={ddClass} title={lb ? 'Focal point' : undefined}>
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        {(() => {
                                                            const m = metadata ?? asset?.metadata ?? {}
                                                            const fp = m.focal_point
                                                            if (!fp || typeof fp.x !== 'number' || typeof fp.y !== 'number') {
                                                                return (
                                                                    <span className={`text-xs ${lb ? 'text-neutral-400' : 'text-gray-500'}`}>
                                                                        Not set — optional for crops
                                                                    </span>
                                                                )
                                                            }
                                                            return (
                                                                <span className={`text-xs ${lb ? 'text-neutral-300' : 'text-gray-600'}`}>
                                                                    {Math.round(fp.x * 100)}% × {Math.round(fp.y * 100)}%
                                                                    {m.focal_point_source === 'ai' && !m.focal_point_locked ? ' · AI' : ''}
                                                                    {m.focal_point_locked ? ' · locked' : ''}
                                                                </span>
                                                            )
                                                        })()}
                                                        <button
                                                            type="button"
                                                            onClick={() => setFocalModalOpen(true)}
                                                            className={`text-xs font-medium ${lb ? 'text-cyan-400 hover:text-cyan-300' : 'text-indigo-600 hover:text-indigo-500'}`}
                                                        >
                                                            Set on image…
                                                        </button>
                                                        {aiEnabled &&
                                                            asset?.category?.slug === 'photography' &&
                                                            !(metadata ?? asset?.metadata ?? {}).focal_point_locked && (
                                                                <button
                                                                    type="button"
                                                                    disabled={focalAiRegenerateLoading}
                                                                    onClick={async () => {
                                                                        if (!asset?.id) return
                                                                        setFocalAiRegenerateLoading(true)
                                                                        try {
                                                                            const url =
                                                                                typeof route === 'function'
                                                                                    ? route('assets.focal-point.ai-regenerate', {
                                                                                          asset: asset.id,
                                                                                      })
                                                                                    : `/app/assets/${asset.id}/focal-point/ai-regenerate`
                                                                            await window.axios.post(url)
                                                                            onToast?.(
                                                                                'AI focal point queued — results appear in a few seconds.',
                                                                                'success',
                                                                            )
                                                                            setTimeout(() => fetchMetadata(), 2500)
                                                                        } catch (err) {
                                                                            onToast?.(
                                                                                err.response?.data?.message ||
                                                                                    'Could not queue AI focal point.',
                                                                                'error',
                                                                            )
                                                                        } finally {
                                                                            setFocalAiRegenerateLoading(false)
                                                                        }
                                                                    }}
                                                                    className={`text-xs font-medium disabled:opacity-50 ${lb ? 'text-violet-300 hover:text-violet-200' : 'text-violet-700 hover:text-violet-600'}`}
                                                                >
                                                                    {focalAiRegenerateLoading ? 'Queuing…' : 'Re-run AI focal'}
                                                                </button>
                                                            )}
                                                    </div>
                                                    <p className={`mt-1 text-[10px] ${lb ? 'text-neutral-500' : 'text-gray-400'}`}>
                                                        Used for smart crops and brand guidelines. Company setting can auto-fill with AI for photography
                                                        (gpt-4o-mini; uses AI credits). Manual “Set on image” locks the point so AI will not overwrite it.
                                                    </p>
                                                </dd>
                                            </>
                                        )}
                                        <dt className={lb ? 'sr-only' : dtClass}>Thumbnail status</dt>
                                        <dd className={ddClass} title={lb ? 'Thumbnail status' : undefined}>
                                            {asset?.thumbnail_status ?? '—'}
                                        </dd>
                                        {(() => {
                                            const aiTagInferenceLine = formatAiTagInferenceSummary(asset?.metadata)
                                            if (!canRegenerateAiMetadataForTroubleshooting || !aiTagInferenceLine) return null
                                            return (
                                                <>
                                                    <dt className={lb ? 'sr-only' : dtClass}>AI tag inference</dt>
                                                    <dd className={ddClass} title={lb ? 'AI tag inference' : aiTagInferenceLine}>
                                                        {aiTagInferenceLine}
                                                    </dd>
                                                </>
                                            )
                                        })()}
                                        {/* Asset ID (UUID) — at bottom for copy/reference */}
                                        {asset?.id && (
                                            <div className={`sm:col-span-2 pt-3 mt-3 border-t ${lb ? 'border-neutral-800/60' : 'border-gray-200'}`}>
                                                <dt className={lb ? 'sr-only' : 'font-semibold mb-1 text-gray-700'}>Asset ID</dt>
                                                <dd
                                                    className={`font-mono break-all ${lb ? 'text-xs text-neutral-100' : 'text-sm text-gray-900'}`}
                                                    title={lb ? 'Asset ID' : asset.id}
                                                >
                                                    {asset.id}
                                                </dd>
                                            </div>
                                        )}
                                    </dl>
                                </div>
                            </CollapsibleSection>
                        </section>

                        {/* Section 4 — Activity (collapsed by default) */}
                        <section className={sectionClass} aria-labelledby="section-activity">
                            <CollapsibleSection variant={collapsibleVariant} titleInCard={lb} title="Activity" defaultExpanded={false}>
                                {lb ? (
                                    <AssetTimeline events={displayActivityEvents} loading={displayActivityLoading} variant="dark" />
                                ) : (
                                    <div className={cardClass}>
                                        <AssetTimeline events={displayActivityEvents} loading={displayActivityLoading} variant="default" />
                                    </div>
                                )}
                            </CollapsibleSection>
                        </section>

                        {/* Section 5 — Versions (Phase 4B: plan-gated, collapsed by default) */}
                        {planAllowsVersions && (
                            <PermissionGate permission="asset.view">
                                <section className={sectionClass} aria-labelledby="section-versions">
                                    <CollapsibleSection
                                        variant={collapsibleVariant}
                                        titleInCard={lb}
                                        title={
                                            versionsLoading ? (
                                                'Versions'
                                            ) : versions.length > 0 ? (
                                                <span className="inline-flex items-center gap-1.5">
                                                    Versions
                                                    <span className={`font-normal ${lb ? 'text-neutral-500' : 'text-gray-400'}`}>({versions.length})</span>
                                                </span>
                                            ) : (
                                                'Versions'
                                            )
                                        }
                                        defaultExpanded={false}
                                    >
                                        <div className={lb ? '' : cardClass}>
                                            {lb && versions.length > 0 && (
                                                <button
                                                    type="button"
                                                    onClick={() => setShowVersionsWideModal(true)}
                                                    className="mb-3 text-left text-xs font-medium text-neutral-300 hover:text-white hover:underline decoration-neutral-600 underline-offset-2"
                                                >
                                                    View full version history ({versions.length})
                                                </button>
                                            )}
                                            {versionsLoading ? (
                                                <div className="animate-pulse space-y-3">
                                                    {[1, 2, 3].map((i) => (
                                                        <div key={i} className={`h-10 rounded ${lb ? 'bg-neutral-800' : 'bg-gray-200'}`} />
                                                    ))}
                                                </div>
                                            ) : versions.length === 0 ? (
                                                <p className={`text-sm ${lb ? 'text-neutral-500' : 'text-gray-500'}`}>No previous versions</p>
                                            ) : !lb ? (
                                                <div className="overflow-x-auto">
                                                    <table className="min-w-full divide-y divide-gray-200">
                                                        <thead>
                                                            <tr>
                                                                <th className="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider w-8 text-gray-500" aria-label="Expand" />
                                                                <th className="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 w-16" aria-label="Preview">
                                                                    Preview
                                                                </th>
                                                                <th className="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Version</th>
                                                                <th className="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                                                                <th className="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Size</th>
                                                                <th className="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Uploaded</th>
                                                                <th className="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">User</th>
                                                                <th className="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Current</th>
                                                                {canRestoreVersion && !readonlyMode && (
                                                                    <th className="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                                                                )}
                                                            </tr>
                                                        </thead>
                                                        <tbody className="divide-y divide-gray-200">
                                                            {versions.map((v) => {
                                                                const status = (v.pipeline_status || 'pending').toLowerCase()
                                                                const statusPillClass =
                                                                    status === 'complete'
                                                                        ? lb
                                                                            ? 'bg-emerald-900/50 text-emerald-200'
                                                                            : 'bg-green-100 text-green-800'
                                                                        : status === 'failed'
                                                                          ? lb
                                                                              ? 'bg-red-950/60 text-red-200'
                                                                              : 'bg-red-100 text-red-800'
                                                                          : lb
                                                                            ? 'bg-amber-900/50 text-amber-100'
                                                                            : 'bg-amber-100 text-amber-800'
                                                                const fmtSize = (b) => (!b ? '—' : b < 1024 ? `${b} B` : b < 1024 * 1024 ? `${(b / 1024).toFixed(1)} KB` : `${(b / (1024 * 1024)).toFixed(1)} MB`)
                                                                const fmtDate = (d) => (!d ? '—' : (() => { try { return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) } catch { return '—' } })())
                                                                const isArchived = ['GLACIER', 'DEEP_ARCHIVE', 'GLACIER_IR'].includes(v.storage_class || '')
                                                                const restoredFrom = v.restored_from_version_id ? versions.find(x => x.id === v.restored_from_version_id) : null
                                                                const isExpanded = expandedVersionId === v.id
                                                                return (
                                                                    <Fragment key={v.id}>
                                                                        <tr className={isArchived ? (lb ? 'bg-neutral-900/50' : 'bg-gray-50') : ''}>
                                                                            <td className="px-4 py-3 text-sm">
                                                                                <button
                                                                                    type="button"
                                                                                    onClick={() => setExpandedVersionId(isExpanded ? null : v.id)}
                                                                                    className={`p-0.5 rounded ${lb ? 'text-neutral-500 hover:text-neutral-200' : 'text-gray-500 hover:text-gray-700'}`}
                                                                                    aria-expanded={isExpanded}
                                                                                >
                                                                                    {isExpanded ? <ChevronDownIcon className="h-4 w-4" /> : <ChevronRightIcon className="h-4 w-4" />}
                                                                                </button>
                                                                            </td>
                                                                            <td className="px-4 py-2 align-middle">
                                                                                {(() => {
                                                                                    const src = versionThumbnailSrc(v)
                                                                                    return src ? (
                                                                                        <img
                                                                                            src={src}
                                                                                            alt=""
                                                                                            className="h-10 w-10 rounded object-cover border border-gray-200 bg-gray-100"
                                                                                            loading="lazy"
                                                                                        />
                                                                                    ) : (
                                                                                        <div
                                                                                            className="flex h-10 w-10 items-center justify-center rounded border border-dashed border-gray-200 bg-gray-50"
                                                                                            title="No preview"
                                                                                        >
                                                                                            <FileTypeIcon mimeType={v.mime_type} size="sm" iconClassName="text-gray-400" />
                                                                                        </div>
                                                                                    )
                                                                                })()}
                                                                            </td>
                                                                            <td className={`px-4 py-3 text-sm font-medium ${lb ? 'text-neutral-100' : 'text-gray-900'}`}>v{v.version_number}</td>
                                                                            <td className="px-4 py-3 text-sm">
                                                                                <span
                                                                                    className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${statusPillClass}`}
                                                                                    title={v.pipeline_status || 'Pipeline status'}
                                                                                >
                                                                                    {status}
                                                                                </span>
                                                                                {isArchived && (
                                                                                    <span
                                                                                        className={`ml-1.5 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${lb ? 'bg-neutral-700 text-neutral-200' : 'bg-slate-200 text-slate-700'}`}
                                                                                        title="This version is archived in Glacier and must be restored before use."
                                                                                    >
                                                                                        Archived
                                                                                    </span>
                                                                                )}
                                                                            </td>
                                                                            <td className={`px-4 py-3 text-sm ${lb ? 'text-neutral-300' : 'text-gray-700'}`}>{fmtSize(v.file_size)}</td>
                                                                            <td className={`px-4 py-3 text-sm ${lb ? 'text-neutral-300' : 'text-gray-700'}`}>{fmtDate(v.created_at)}</td>
                                                                            <td className={`px-4 py-3 text-sm ${lb ? 'text-neutral-300' : 'text-gray-700'}`}>{v.uploaded_by?.name ?? '—'}</td>
                                                                            <td className="px-4 py-3 text-sm">
                                                                                {v.is_current && (
                                                                                    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${lb ? 'bg-neutral-800 text-neutral-100' : 'bg-indigo-100 text-indigo-800'}`} title="Current version">
                                                                                        <CheckIcon className="h-3.5 w-3.5 mr-0.5" aria-hidden />
                                                                                        Current
                                                                                    </span>
                                                                                )}
                                                                            </td>
                                                                            {canRestoreVersion && !readonlyMode && (
                                                                                <td className="px-4 py-3 text-sm">
                                                                                    {!v.is_current && (
                                                                                        isArchived ? (
                                                                                            <span
                                                                                                className={`cursor-not-allowed ${lb ? 'text-neutral-600' : 'text-gray-400'}`}
                                                                                                title="This version is archived in Glacier and must be restored before use."
                                                                                            >
                                                                                                Restore
                                                                                            </span>
                                                                                        ) : (
                                                                                            <button
                                                                                                type="button"
                                                                                                onClick={() => {
                                                                                                    setRestoreVersion(v)
                                                                                                    setRestorePreserveMetadata(true)
                                                                                                    setRestoreRerunPipeline(false)
                                                                                                    setShowRestoreModal(true)
                                                                                                }}
                                                                                                className={`font-medium ${lb ? 'text-neutral-200 hover:text-white hover:underline decoration-neutral-600 underline-offset-2' : 'text-indigo-600 hover:text-indigo-800'}`}
                                                                                            >
                                                                                                Restore
                                                                                            </button>
                                                                                        )
                                                                                    )}
                                                                                </td>
                                                                            )}
                                                                        </tr>
                                                                        {isExpanded && (
                                                                            <tr key={`${v.id}-expanded`}>
                                                                                <td colSpan={canRestoreVersion && !readonlyMode ? 9 : 8} className={`px-4 py-3 text-sm border-b ${lb ? 'bg-neutral-900/80 border-neutral-700 text-neutral-300' : 'bg-gray-50 border-gray-200 text-gray-600'}`}>
                                                                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                                                                        {v.change_note && <div><span className={`font-medium ${lb ? 'text-neutral-200' : 'text-gray-700'}`}>Comment:</span> {v.change_note}</div>}
                                                                                        {restoredFrom && <div><span className={`font-medium ${lb ? 'text-neutral-200' : 'text-gray-700'}`}>Restored from:</span> v{restoredFrom.version_number}</div>}
                                                                                        {v.storage_class && <div><span className={`font-medium ${lb ? 'text-neutral-200' : 'text-gray-700'}`}>Storage:</span> {v.storage_class}</div>}
                                                                                        <div><span className={`font-medium ${lb ? 'text-neutral-200' : 'text-gray-700'}`}>Pipeline:</span> {status}</div>
                                                                                        <div><span className={`font-medium ${lb ? 'text-neutral-200' : 'text-gray-700'}`}>Uploaded by:</span> {v.uploaded_by?.name ?? '—'}</div>
                                                                                    </div>
                                                                                </td>
                                                                            </tr>
                                                                        )}
                                                                    </Fragment>
                                                                )
                                                            })}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            ) : (
                                                <div className="space-y-2">
                                                    {versions.map((v) => {
                                                        const fmtSize = (b) => (!b ? '—' : b < 1024 ? `${b} B` : b < 1024 * 1024 ? `${(b / 1024).toFixed(1)} KB` : `${(b / (1024 * 1024)).toFixed(1)} MB`)
                                                        const fmtDate = (d) => (!d ? '—' : (() => { try { return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) } catch { return '—' } })())
                                                        const status = (v.pipeline_status || 'pending').toLowerCase()
                                                        const thumbSrc = versionThumbnailSrc(v)
                                                        return (
                                                            <div key={v.id} className="rounded-lg border border-neutral-800/70 bg-neutral-900/40 px-3 py-2.5 text-xs">
                                                                <div className="flex gap-3">
                                                                    <div className="shrink-0">
                                                                        {thumbSrc ? (
                                                                            <img
                                                                                src={thumbSrc}
                                                                                alt=""
                                                                                className="h-14 w-14 rounded-md object-cover border border-neutral-700 bg-neutral-800"
                                                                                loading="lazy"
                                                                            />
                                                                        ) : (
                                                                            <div
                                                                                className="flex h-14 w-14 items-center justify-center rounded-md border border-dashed border-neutral-600 bg-neutral-800/80"
                                                                                title="No preview"
                                                                            >
                                                                                <FileTypeIcon mimeType={v.mime_type} size="sm" iconClassName="text-neutral-500" />
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                    <div className="min-w-0 flex-1">
                                                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                                                            <span className="font-semibold text-neutral-100">v{v.version_number}</span>
                                                                            <span className="text-[10px] font-medium uppercase tracking-wide text-neutral-500">{status}</span>
                                                                        </div>
                                                                        <div className="mt-1 text-sm text-neutral-400">
                                                                            {fmtSize(v.file_size)} · {fmtDate(v.created_at)}
                                                                            {v.uploaded_by?.name ? ` · ${v.uploaded_by.name}` : ''}
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        )
                                                    })}
                                                </div>
                                            )}
                                        </div>
                                    </CollapsibleSection>
                                </section>
                            </PermissionGate>
                        )}

                        {/* Download history: same roles as before; only rendered when activity includes download events */}
                        {showDownloadHistorySection && (
                            <section className={sectionClass} aria-labelledby="section-downloads">
                                <CollapsibleSection variant={collapsibleVariant} titleInCard={lb} title="Download history" defaultExpanded={false}>
                                    <div className={lb ? 'space-y-2' : `${cardClass} space-y-2`}>
                                        {downloadHistoryEvents.map((ev) => {
                                            const label =
                                                ev.event_type === 'asset.download.created'
                                                    ? 'Download requested'
                                                    : ev.event_type === 'asset.download.completed'
                                                      ? 'Download completed'
                                                      : ev.event_type === 'asset.download.failed'
                                                        ? 'Download failed'
                                                        : 'Download'
                                            const when = ev.created_at
                                                ? (() => {
                                                      try {
                                                          return new Date(ev.created_at).toLocaleString(undefined, {
                                                              dateStyle: 'medium',
                                                              timeStyle: 'short',
                                                          })
                                                      } catch {
                                                          return '—'
                                                      }
                                                  })()
                                                : '—'
                                            return (
                                                <div
                                                    key={ev.id}
                                                    className={`flex flex-wrap items-baseline justify-between gap-2 border-b pb-2 last:border-0 last:pb-0 ${lb ? 'border-neutral-800' : 'border-gray-100'}`}
                                                >
                                                    <span className={`text-sm ${lb ? 'text-neutral-200' : 'text-gray-900'}`}>{label}</span>
                                                    <span className={`text-xs ${lb ? 'text-neutral-500' : 'text-gray-500'}`}>{when}</span>
                                                </div>
                                            )
                                        })}
                                    </div>
                                </CollapsibleSection>
                            </section>
                        )}
                    </div>

                    <GuidelinesFocalPointModal
                        open={focalModalOpen}
                        onClose={() => setFocalModalOpen(false)}
                        imageUrl={
                            asset?.final_thumbnail_url ||
                            asset?.thumbnail_url ||
                            asset?.preview_thumbnail_url ||
                            null
                        }
                        initialFocal={metadata?.focal_point ?? asset?.metadata?.focal_point}
                        assetId={asset?.id}
                        saveMode="library"
                        onSaved={() => {
                            fetchMetadata()
                        }}
                    />

                    {/* Phase 5C: Restore Version Modal */}
                    {showRestoreModal && restoreVersion && (
                        <div className="fixed inset-0 z-50 overflow-y-auto">
                            <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                                <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={() => !restoreLoading && setShowRestoreModal(false)} />
                                <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                                    <h3 className="text-base font-semibold leading-6 text-gray-900 mb-4">
                                        Restore v{restoreVersion.version_number}?
                                    </h3>
                                    <div className="space-y-3 mb-6">
                                        <label className="flex items-start gap-3 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={restorePreserveMetadata}
                                                onChange={(e) => setRestorePreserveMetadata(e.target.checked)}
                                                className="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            />
                                            <span className="text-sm text-gray-700">Preserve historical metadata (recommended)</span>
                                        </label>
                                        <label className="flex items-start gap-3 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={restoreRerunPipeline}
                                                onChange={(e) => setRestoreRerunPipeline(e.target.checked)}
                                                className="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            />
                                            <span className="text-sm text-gray-700">Reprocess with current pipeline</span>
                                        </label>
                                    </div>
                                    <div className="flex justify-end gap-3">
                                        <button
                                            type="button"
                                            onClick={() => !restoreLoading && setShowRestoreModal(false)}
                                            disabled={restoreLoading}
                                            className="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="button"
                                            onClick={handleRestoreVersion}
                                            disabled={restoreLoading}
                                            className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                        >
                                            {restoreLoading ? 'Restoring…' : 'Restore'}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Lightbox: full-width version history (wide table) */}
                    {showVersionsWideModal && lb && versions.length > 0 && (
                        <div
                            className="fixed inset-0 z-[70] flex items-center justify-center bg-black/80 p-4"
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="versions-wide-title"
                            onClick={() => setShowVersionsWideModal(false)}
                        >
                            <div
                                className="flex max-h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-xl border border-neutral-700 bg-neutral-950 shadow-2xl"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <div className="flex items-center justify-between border-b border-neutral-800 px-4 py-3">
                                    <h2 id="versions-wide-title" className="text-sm font-semibold text-white">
                                        Version history
                                    </h2>
                                    <button
                                        type="button"
                                        className="rounded-md p-2 text-neutral-400 hover:bg-neutral-800 hover:text-white"
                                        onClick={() => setShowVersionsWideModal(false)}
                                        aria-label="Close"
                                    >
                                        <XMarkIcon className="h-6 w-6" />
                                    </button>
                                </div>
                                <div className="overflow-auto p-4">
                                    <div className="overflow-x-auto">
                                        <table className="min-w-[980px] w-full divide-y divide-neutral-700 text-xs">
                                            <thead>
                                                <tr>
                                                    <th className="px-3 py-2 text-left font-medium uppercase tracking-wider text-neutral-500 w-16" aria-label="Preview">
                                                        Preview
                                                    </th>
                                                    <th className="px-3 py-2 text-left font-medium uppercase tracking-wider text-neutral-500">Version</th>
                                                    <th className="px-3 py-2 text-left font-medium uppercase tracking-wider text-neutral-500">Status</th>
                                                    <th className="px-3 py-2 text-left font-medium uppercase tracking-wider text-neutral-500">Size</th>
                                                    <th className="px-3 py-2 text-left font-medium uppercase tracking-wider text-neutral-500">Uploaded</th>
                                                    <th className="px-3 py-2 text-left font-medium uppercase tracking-wider text-neutral-500">User</th>
                                                    <th className="px-3 py-2 text-left font-medium uppercase tracking-wider text-neutral-500">Current</th>
                                                    {canRestoreVersion && !readonlyMode && (
                                                        <th className="px-3 py-2 text-left font-medium uppercase tracking-wider text-neutral-500">Actions</th>
                                                    )}
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-neutral-700">
                                                {versions.map((v) => {
                                                    const status = (v.pipeline_status || 'pending').toLowerCase()
                                                    const statusPillClass =
                                                        status === 'complete'
                                                            ? 'bg-emerald-900/50 text-emerald-200'
                                                            : status === 'failed'
                                                              ? 'bg-red-950/60 text-red-200'
                                                              : 'bg-amber-900/50 text-amber-100'
                                                    const fmtSize = (b) =>
                                                        !b ? '—' : b < 1024 ? `${b} B` : b < 1024 * 1024 ? `${(b / 1024).toFixed(1)} KB` : `${(b / (1024 * 1024)).toFixed(1)} MB`
                                                    const fmtDate = (d) =>
                                                        !d
                                                            ? '—'
                                                            : (() => {
                                                                  try {
                                                                      return new Date(d).toLocaleDateString('en-US', {
                                                                          month: 'short',
                                                                          day: 'numeric',
                                                                          year: 'numeric',
                                                                      })
                                                                  } catch {
                                                                      return '—'
                                                                  }
                                                              })()
                                                    const isArchived = ['GLACIER', 'DEEP_ARCHIVE', 'GLACIER_IR'].includes(v.storage_class || '')
                                                    return (
                                                        <tr key={v.id} className={isArchived ? 'bg-neutral-900/50' : ''}>
                                                            <td className="px-3 py-2 align-middle">
                                                                {(() => {
                                                                    const src = versionThumbnailSrc(v)
                                                                    return src ? (
                                                                        <img
                                                                            src={src}
                                                                            alt=""
                                                                            className="h-11 w-11 rounded object-cover border border-neutral-700 bg-neutral-900"
                                                                            loading="lazy"
                                                                        />
                                                                    ) : (
                                                                        <div
                                                                            className="flex h-11 w-11 items-center justify-center rounded border border-dashed border-neutral-600 bg-neutral-900/80"
                                                                            title="No preview"
                                                                        >
                                                                            <FileTypeIcon mimeType={v.mime_type} size="sm" iconClassName="text-neutral-500" />
                                                                        </div>
                                                                    )
                                                                })()}
                                                            </td>
                                                            <td className="px-3 py-2.5 font-medium text-neutral-100">v{v.version_number}</td>
                                                            <td className="px-3 py-2.5">
                                                                <span
                                                                    className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium ${statusPillClass}`}
                                                                >
                                                                    {status}
                                                                </span>
                                                                {isArchived && (
                                                                    <span className="ml-1.5 inline-flex rounded-full bg-neutral-700 px-2 py-0.5 text-[11px] text-neutral-200">
                                                                        Archived
                                                                    </span>
                                                                )}
                                                            </td>
                                                            <td className="px-3 py-2.5 text-neutral-300">{fmtSize(v.file_size)}</td>
                                                            <td className="px-3 py-2.5 text-neutral-300">{fmtDate(v.created_at)}</td>
                                                            <td className="px-3 py-2.5 text-neutral-300">{v.uploaded_by?.name ?? '—'}</td>
                                                            <td className="px-3 py-2.5">
                                                                {v.is_current && (
                                                                    <span className="inline-flex items-center rounded-full bg-neutral-800 px-2 py-0.5 text-[11px] text-neutral-100">
                                                                        <CheckIcon className="mr-0.5 h-3 w-3" aria-hidden />
                                                                        Current
                                                                    </span>
                                                                )}
                                                            </td>
                                                            {canRestoreVersion && !readonlyMode && (
                                                                <td className="px-3 py-2.5">
                                                                    {!v.is_current &&
                                                                        (isArchived ? (
                                                                            <span className="cursor-not-allowed text-neutral-600">Restore</span>
                                                                        ) : (
                                                                            <button
                                                                                type="button"
                                                                                className="font-medium text-neutral-200 hover:text-white hover:underline decoration-neutral-600 underline-offset-2"
                                                                                onClick={() => {
                                                                                    setRestoreVersion(v)
                                                                                    setRestorePreserveMetadata(true)
                                                                                    setRestoreRerunPipeline(false)
                                                                                    setShowVersionsWideModal(false)
                                                                                    setShowRestoreModal(true)
                                                                                }}
                                                                            >
                                                                                Restore
                                                                            </button>
                                                                        ))}
                                                                </td>
                                                            )}
                                                        </tr>
                                                    )
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Tags at bottom */}
                    {!loading && asset?.id && (
                        <div
                            className={`border-t px-5 py-6 ${
                                lb
                                    ? 'border-neutral-800 bg-neutral-950'
                                    : 'border-gray-200 p-8'
                            }`}
                        >
                            <AssetTagManager
                                asset={asset}
                                showTitle
                                showInput={false}
                                detailed
                                readOnly={readonlyMode}
                                primaryColor={lb ? lbAccent : brandPrimary}
                                variant={lb ? 'dark' : 'default'}
                            />
                        </div>
                    )}
                </div>

                {!fullPage && (
                    <div
                        className={`flex-shrink-0 border-t p-4 flex justify-end ${embeddedInLightbox ? 'md:hidden' : ''} ${
                            embeddedInLightbox ? 'border-neutral-800 bg-neutral-950' : 'border-gray-200 bg-gray-50'
                        }`}
                    >
                        <button
                            type="button"
                            onClick={handleRequestClose}
                            className={
                                embeddedInLightbox
                                    ? 'rounded-md border border-neutral-600 bg-neutral-900 px-4 py-2 text-sm font-medium text-neutral-100 shadow-sm hover:bg-neutral-800'
                                    : 'rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50'
                            }
                        >
                            {embeddedInLightbox ? (readonlyMode ? 'Close' : 'Hide details') : 'Close'}
                        </button>
                    </div>
                )}
            </div>
        </>
    )
}
