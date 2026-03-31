/**
 * Bulk Metadata Edit Modal Component
 *
 * Phase 2 – Step 7: Multi-step modal for bulk metadata operations.
 *
 * Steps:
 * 1. Select operation type (Add / Replace / Clear)
 * 2. Select metadata field(s)
 * 3. Enter value(s)
 * 4. Preview changes
 * 5. Execute with progress
 */

import { useState, useEffect } from 'react'
import { usePage } from '@inertiajs/react'
import { XMarkIcon, CheckIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline'
import MetadataFieldInput from './Upload/MetadataFieldInput'
import CollectionSelector from './Collections/CollectionSelector'
import CreateCollectionModal from './Collections/CreateCollectionModal'
import ConfirmDialog from './ConfirmDialog'

export default function BulkMetadataEditModal({
    assetIds,
    onClose,
    onComplete,
    /** When provided (e.g. from Actions modal), skip step 1 and go directly to field selection */
    initialOperation = null,
}) {
    const { auth } = usePage().props
    const [step, setStep] = useState(initialOperation ? 2 : 1) // 1: operation, 2: field, 3: value, 4: preview, 5: execute
    const [operationType, setOperationType] = useState(initialOperation || 'add') // 'add' | 'replace' | 'clear'
    const [selectedField, setSelectedField] = useState(null)
    const [value, setValue] = useState(null)
    const [preview, setPreview] = useState(null)
    const [previewToken, setPreviewToken] = useState(null)
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState(null)
    const [editableFields, setEditableFields] = useState([])
    const [executing, setExecuting] = useState(false)
    const [executeProgress, setExecuteProgress] = useState(0)
    const [results, setResults] = useState(null)
    /** C9.2: Collections support */
    const [collectionsList, setCollectionsList] = useState([])
    const [collectionsListLoading, setCollectionsListLoading] = useState(false)
    const [collectionFieldVisible, setCollectionFieldVisible] = useState(false)
    const [firstAssetCategoryId, setFirstAssetCategoryId] = useState(null)
    const [selectedCollectionIds, setSelectedCollectionIds] = useState([])
    const [showCategoryChangeConfirm, setShowCategoryChangeConfirm] = useState(false)
    const [pendingCategoryField, setPendingCategoryField] = useState(null)
    const [showCreateCollectionModal, setShowCreateCollectionModal] = useState(false)

    const canShowCategoryWarning = () => {
        const brandRole = (auth?.user?.brand_role || auth?.brand_role || '').toLowerCase()
        const tenantRole = (auth?.user?.tenant_role || auth?.tenant_role || '').toLowerCase()
        return ['brand_owner', 'brand_admin', 'owner', 'admin'].includes(brandRole) || ['owner', 'admin'].includes(tenantRole)
    }

    // C9.2: Fetch first asset's metadata to get category for collection field visibility check
    // Uses GET /assets/{asset}/metadata/all (no bare GET /assets/{asset} route exists)
    useEffect(() => {
        if (assetIds.length > 0) {
            fetch(`/app/assets/${assetIds[0]}/metadata/all`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            })
            .then((res) => {
                if (!res.ok) throw new Error(res.status)
                return res.json()
            })
            .then((data) => {
                const categoryId = data.category?.id ?? null
                setFirstAssetCategoryId(categoryId)
            })
            .catch(() => {
                setFirstAssetCategoryId(null)
            })
        }
    }, [assetIds])

    // Fetch collections list immediately on mount
    const fetchCollectionsList = () => {
        setCollectionsListLoading(true)
        fetch('/app/collections/list', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((data) => {
                setCollectionsList(data?.collections ?? [])
            })
            .catch(() => setCollectionsList([]))
            .finally(() => setCollectionsListLoading(false))
    }

    useEffect(() => {
        fetchCollectionsList()
    }, [])

    // Check collection field visibility using upload metadata schema
    useEffect(() => {
        if (!firstAssetCategoryId) {
            setCollectionFieldVisible(false)
            return
        }

        const params = new URLSearchParams({
            category_id: firstAssetCategoryId.toString(),
            asset_type: 'image',
            context: 'edit',
        })

        fetch(`/app/uploads/metadata-schema?${params.toString()}`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Failed to fetch metadata schema: ${response.status}`)
                }
                return response.json()
            })
            .then((data) => {
                if (data.error) {
                    throw new Error(data.message || 'Failed to load metadata schema')
                }
                const hasCollectionField = data.groups?.some(group => 
                    (group.fields || []).some(field => field.key === 'collection')
                ) || false
                setCollectionFieldVisible(hasCollectionField)
            })
            .catch(() => {
                setCollectionFieldVisible(false)
            })
    }, [firstAssetCategoryId])

    // Fetch editable fields (use first asset's category as reference)
    useEffect(() => {
        if (step >= 2 && assetIds.length > 0) {
            fetch(`/app/assets/${assetIds[0]}/metadata/editable`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })
            .then((res) => res.json())
            .then((data) => {
                const editableOnly = (data.fields || []).filter(
                    field => !(field.readonly || field.population_mode === 'automatic')
                        && field.field_key !== 'collection'
                )
                setEditableFields(editableOnly)
            })
                .catch((err) => {
                    console.error('[BulkMetadataEditModal] Failed to fetch fields', err)
                    setError('Failed to load editable fields')
                })
        }
    }, [step, assetIds])

    // Handle operation selection
    const handleOperationSelect = (type) => {
        setOperationType(type)
        setStep(2)
        setError(null)
    }

    // Handle field selection
    const handleFieldSelect = (field) => {
        const isCategoryField = field && typeof field === 'object' && (field.field_key === 'category' || field.field_key === 'category_id')
        if (isCategoryField && assetIds.length > 1 && canShowCategoryWarning()) {
            setPendingCategoryField(field)
            setShowCategoryChangeConfirm(true)
            return
        }
        applyFieldSelect(field)
    }

    const applyFieldSelect = (field) => {
        setSelectedField(field)
        setPendingCategoryField(null)
        // C9.2: For collections, initialize with empty array
        if (field === 'collections') {
            setValue([])
        } else if (field && typeof field === 'object') {
            setValue(field.current_value ?? null)
        }
        setStep(3)
        setError(null)
    }

    // Handle preview
    const handlePreview = async () => {
        // C9.2: For collections, validate selectedCollectionIds
        if (selectedField === 'collections') {
            if (operationType !== 'clear' && selectedCollectionIds.length === 0 && value === null) {
                setError('Please select at least one collection or use Clear operation')
                return
            }
        } else if (!selectedField || (operationType !== 'clear' && value === null)) {
            setError('Please select a field and enter a value')
            return
        }

        setLoading(true)
        setError(null)

        try {
            // C9.2: For collections, use sync endpoint preview (simulate for each asset)
            if (selectedField === 'collections') {
                // For collections, we'll preview by checking current collections for each asset
                const previewData = {
                    total_assets: assetIds.length,
                    affected_assets: [],
                    errors: [],
                }

                // Fetch current collections for each asset to build preview
                const previewPromises = assetIds.map(async (assetId) => {
                    try {
                        const res = await fetch(`/app/assets/${assetId}/collections`, {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        })
                        const data = await res.json()
                        const currentCollectionIds = (data.collections || []).filter(Boolean).map((c) => c?.id).filter(Boolean)
                        const newCollectionIds = operationType === 'clear' ? [] : selectedCollectionIds
                        const willChange = JSON.stringify(currentCollectionIds.sort()) !== JSON.stringify(newCollectionIds.sort())

                        if (willChange) {
                            return {
                                asset_id: assetId,
                                asset_title: `Asset ${assetId.substring(0, 8)}...`,
                                changes: [{
                                    field_label: 'Collections',
                                    old_value: currentCollectionIds.length > 0 ? `${currentCollectionIds.length} collection(s)` : 'None',
                                    new_value: newCollectionIds.length > 0 ? `${newCollectionIds.length} collection(s)` : 'None',
                                }],
                            }
                        }
                        return null
                    } catch (err) {
                        return {
                            asset_id: assetId,
                            asset_title: `Asset ${assetId.substring(0, 8)}...`,
                            errors: [err.message || 'Failed to preview'],
                        }
                    }
                })

                const previewResults = await Promise.all(previewPromises)
                previewData.affected_assets = previewResults.filter((r) => r !== null && !r.errors)
                previewData.errors = previewResults.filter((r) => r?.errors).map((r) => ({
                    asset_title: r.asset_title,
                    errors: r.errors,
                }))

                // Generate a preview token (simple hash of operation)
                const previewTokenData = {
                    operation_type: operationType,
                    field: 'collections',
                    collection_ids: operationType === 'clear' ? [] : selectedCollectionIds,
                    asset_ids: assetIds,
                }
                const previewToken = btoa(JSON.stringify(previewTokenData))

                setPreview(previewData)
                setPreviewToken(previewToken)
                setStep(4)
            } else {
                // Regular metadata field preview
                const response = await fetch('/app/assets/metadata/bulk/preview', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        asset_ids: assetIds,
                        operation_type: operationType,
                        metadata: {
                            [selectedField.field_key]: operationType === 'clear' ? null : value,
                        },
                    }),
                })

                if (!response.ok) {
                    const data = await response.json()
                    throw new Error(data.message || 'Preview failed')
                }

                const data = await response.json()
                setPreview(data.preview)
                setPreviewToken(data.preview_token)
                setStep(4)
            }
        } catch (err) {
            console.error('[BulkMetadataEditModal] Preview failed', err)
            setError(err.message || 'Failed to preview changes')
        } finally {
            setLoading(false)
        }
    }

    // Handle execute
    const handleExecute = async () => {
        if (selectedField === 'collections') {
            return handleExecuteCollections()
        }

        if (!previewToken) {
            setError('Preview expired or missing. Go back and run Preview again.')
            return
        }

        setExecuting(true)
        setExecuteProgress(0)
        setError(null)

        try {
            const res = await fetch('/app/assets/metadata/bulk/execute', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ preview_token: previewToken }),
            })

            const data = await res.json().catch(() => ({}))
            if (!res.ok) {
                throw new Error(data.message || `Execution failed (${res.status})`)
            }

            const r = data.results || {}
            setResults({
                successes: r.successes || [],
                failures: r.failures || [],
            })
            setExecuteProgress(assetIds.length)
            setStep(5)
        } catch (err) {
            console.error('[BulkMetadataEditModal] Execute failed', err)
            setError(err.message || 'Failed to apply bulk metadata changes')
        } finally {
            setExecuting(false)
        }
    }

    // Execute bulk collection assignment via per-asset sync
    const handleExecuteCollections = async () => {
        setExecuting(true)
        setExecuteProgress(0)
        setError(null)

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
        const collectionIds = operationType === 'clear' ? [] : selectedCollectionIds
        const successes = []
        const failures = []

        for (let i = 0; i < assetIds.length; i++) {
            const assetId = assetIds[i]
            setExecuteProgress(i + 1)
            try {
                let idsToSync = collectionIds

                if (operationType === 'add' && collectionIds.length > 0) {
                    const currentRes = await fetch(`/app/assets/${assetId}/collections`, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    })
                    const currentData = await currentRes.json()
                    const currentIds = (currentData.collections || []).map((c) => c?.id).filter(Boolean)
                    const merged = [...new Set([...currentIds, ...collectionIds])]
                    idsToSync = merged
                }

                const res = await fetch(`/app/assets/${assetId}/collections`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ collection_ids: idsToSync }),
                })

                if (!res.ok) {
                    const errData = await res.json().catch(() => ({}))
                    throw new Error(errData.message || `HTTP ${res.status}`)
                }

                successes.push({ asset_id: assetId })
            } catch (err) {
                failures.push({
                    asset_id: assetId,
                    asset_title: `Asset ${assetId.substring(0, 8)}…`,
                    error: err.message || 'Unknown error',
                })
            }
        }

        setResults({ successes, failures })
        setStep(5)
        setExecuting(false)
    }

    // Format value for display
    const formatValue = (val) => {
        if (val === null || val === undefined) return 'Not set'
        if (Array.isArray(val)) return val.join(', ')
        if (typeof val === 'boolean') return val ? 'Yes' : 'No'
        return String(val)
    }

    return (
        <>
            {/* Backdrop */}
            <div
                className="fixed inset-0 bg-black/50 z-50 transition-opacity"
                onClick={onClose}
                aria-hidden="true"
            />

            {/* Modal - items-start on mobile so modal appears at top, not low */}
            <div
                className="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-4 pt-8 sm:pt-4 overflow-y-auto"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[calc(100vh-5.5rem-env(safe-area-inset-bottom,0px))] sm:max-h-[90vh] overflow-y-auto">
                    {/* Header */}
                    <div className="sticky top-0 z-10 bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between">
                        <h3 className="text-lg font-semibold text-gray-900">
                            Bulk Edit Metadata ({assetIds.length} assets)
                        </h3>
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={executing}
                            className="flex-shrink-0 rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
                        >
                            <span className="sr-only">Close</span>
                            <XMarkIcon className="h-6 w-6" aria-hidden="true" />
                        </button>
                    </div>

                    {/* Content */}
                    <div className="px-6 py-6">
                        {error && (
                            <div className="mb-4 rounded-md bg-red-50 p-4">
                                <div className="text-sm text-red-800">{error}</div>
                            </div>
                        )}

                        {/* Step 1: Operation Type */}
                        {step === 1 && (
                            <div className="space-y-4">
                                <h4 className="text-sm font-medium text-gray-900">Select Operation</h4>
                                <div className="space-y-2">
                                    <button
                                        type="button"
                                        onClick={() => handleOperationSelect('add')}
                                        className="w-full text-left px-4 py-3 border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    >
                                        <div className="font-medium text-gray-900">Add</div>
                                        <div className="text-sm text-gray-500">Add metadata values without affecting existing values</div>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleOperationSelect('replace')}
                                        className="w-full text-left px-4 py-3 border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    >
                                        <div className="font-medium text-gray-900">Replace</div>
                                        <div className="text-sm text-gray-500">Add new values (old values remain for audit)</div>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleOperationSelect('clear')}
                                        className="w-full text-left px-4 py-3 border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    >
                                        <div className="font-medium text-gray-900">Clear</div>
                                        <div className="text-sm text-gray-500">Clear metadata values (old values remain for audit)</div>
                                    </button>
                                </div>
                            </div>
                        )}

                        {/* Step 2: Field Selection */}
                        {step === 2 && (
                            <div className="space-y-4">
                                <div className="flex items-center justify-between">
                                    <h4 className="text-sm font-medium text-gray-900">Select Field</h4>
                                    <button
                                        type="button"
                                        onClick={() => initialOperation ? onClose() : setStep(1)}
                                        className="text-sm text-indigo-600 hover:text-indigo-700"
                                    >
                                        {initialOperation ? 'Cancel' : 'Back'}
                                    </button>
                                </div>
                                <div className="space-y-2">
                                    <button
                                        type="button"
                                        onClick={() => handleFieldSelect('collections')}
                                        className="w-full text-left px-4 py-3 border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    >
                                        <div className="font-medium text-gray-900">Collections</div>
                                        <div className="text-sm text-gray-500">
                                            Assign assets to collections (add/remove)
                                        </div>
                                    </button>
                                    {editableFields.map((field) => (
                                        <button
                                            key={field.metadata_field_id}
                                            type="button"
                                            onClick={() => handleFieldSelect(field)}
                                            className="w-full text-left px-4 py-3 border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                        >
                                            <div className="font-medium text-gray-900">{field.display_label}</div>
                                            <div className="text-sm text-gray-500">
                                                {field.type} • Current: {formatValue(field.current_value)}
                                            </div>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Step 3: Value Entry */}
                        {step === 3 && selectedField && (
                            <div className="space-y-4">
                                <div className="flex items-center justify-between">
                                    <h4 className="text-sm font-medium text-gray-900">Enter Value</h4>
                                    <button
                                        type="button"
                                        onClick={() => setStep(2)}
                                        className="text-sm text-indigo-600 hover:text-indigo-700"
                                    >
                                        Back
                                    </button>
                                </div>
                                {operationType === 'clear' ? (
                                    <div className="p-4 bg-yellow-50 border border-yellow-200 rounded-md">
                                        <div className="flex items-start gap-2">
                                            <ExclamationTriangleIcon className="h-5 w-5 text-yellow-600 mt-0.5" />
                                            <div>
                                                <div className="text-sm font-medium text-yellow-800">Clear Operation</div>
                                                <div className="text-sm text-yellow-700 mt-1">
                                                    {selectedField === 'collections' 
                                                        ? 'This will remove all collections from all selected assets.'
                                                        : `This will clear the "${selectedField.display_label}" field for all selected assets.`}
                                                    Previous values will remain in the audit history.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                ) : selectedField === 'collections' ? (
                                    /* C9.2: CollectionSelector for bulk collection assignment */
                                    <div className="space-y-2">
                                        <label className="block text-sm font-medium text-gray-700">
                                            Select Collections
                                        </label>
                                        <p className="text-xs text-gray-500 mb-2">
                                            This will sync collections for {assetIds.length} asset{assetIds.length !== 1 ? 's' : ''}. Selected collections will be added, unselected will be removed.
                                        </p>
                                        {collectionsListLoading ? (
                                            <p className="text-sm text-gray-500">Loading collections…</p>
                                        ) : (
                                            <CollectionSelector
                                                collections={collectionsList}
                                                selectedIds={selectedCollectionIds}
                                                onChange={setSelectedCollectionIds}
                                                disabled={false}
                                                placeholder="Select collections…"
                                                showCreateButton={true}
                                                onCreateClick={() => setShowCreateCollectionModal(true)}
                                            />
                                        )}
                                    </div>
                                ) : (
                                    <MetadataFieldInput
                                        field={selectedField}
                                        value={value}
                                        onChange={setValue}
                                        disabled={false}
                                        showError={false}
                                        isUploadContext={false}
                                    />
                                )}
                                <button
                                    type="button"
                                    onClick={handlePreview}
                                    disabled={loading || (operationType !== 'clear' && selectedField === 'collections' ? selectedCollectionIds.length === 0 : value === null)}
                                    className="w-full px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {loading ? 'Generating Preview...' : 'Preview Changes'}
                                </button>
                            </div>
                        )}

                        {/* Step 4: Preview */}
                        {step === 4 && preview && (
                            <div className="space-y-4">
                                <div className="flex items-center justify-between">
                                    <h4 className="text-sm font-medium text-gray-900">Preview Changes</h4>
                                    <button
                                        type="button"
                                        onClick={() => setStep(3)}
                                        className="text-sm text-indigo-600 hover:text-indigo-700"
                                    >
                                        Back
                                    </button>
                                </div>

                                <div className="space-y-4">
                                    <div className="p-4 bg-blue-50 border border-blue-200 rounded-md">
                                        <div className="text-sm font-medium text-blue-900">
                                            {preview.total_assets} assets selected
                                        </div>
                                        <div className="text-sm text-blue-700 mt-1">
                                            {preview.affected_assets.length} assets will be modified
                                        </div>
                                    </div>

                                    {preview.errors.length > 0 && (
                                        <div className="p-4 bg-red-50 border border-red-200 rounded-md">
                                            <div className="text-sm font-medium text-red-900 mb-2">
                                                Errors ({preview.errors.length})
                                            </div>
                                            <div className="space-y-1">
                                                {preview.errors.slice(0, 5).map((err, idx) => (
                                                    <div key={idx} className="text-sm text-red-700">
                                                        {err.asset_title}: {err.errors.join(', ')}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}

                                    {preview.affected_assets.length > 0 && (
                                        <div className="space-y-2 max-h-64 overflow-y-auto">
                                            {preview.affected_assets.slice(0, 10).map((asset, idx) => (
                                                <div key={idx} className="p-3 border border-gray-200 rounded-md">
                                                    <div className="text-sm font-medium text-gray-900">{asset.asset_title}</div>
                                                    <div className="mt-1 space-y-1">
                                                        {asset.changes.map((change, cIdx) => (
                                                            <div key={cIdx} className="text-xs text-gray-600">
                                                                <span className="font-medium">{change.field_label}:</span>{' '}
                                                                {formatValue(change.old_value)} → {formatValue(change.new_value)}
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            ))}
                                            {preview.affected_assets.length > 10 && (
                                                <div className="text-sm text-gray-500 text-center">
                                                    ... and {preview.affected_assets.length - 10} more
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>

                                {executing && selectedField === 'collections' && (
                                    <div className="space-y-2">
                                        <div className="flex justify-between text-xs text-gray-600">
                                            <span>Processing assets…</span>
                                            <span>{executeProgress} / {assetIds.length}</span>
                                        </div>
                                        <div className="w-full bg-gray-200 rounded-full h-1.5">
                                            <div
                                                className="bg-green-600 h-1.5 rounded-full transition-all duration-200"
                                                style={{ width: `${(executeProgress / assetIds.length) * 100}%` }}
                                            />
                                        </div>
                                    </div>
                                )}
                                {executing && selectedField !== 'collections' && (
                                    <p className="text-sm text-gray-600">Applying changes…</p>
                                )}
                                <button
                                    type="button"
                                    onClick={handleExecute}
                                    disabled={executing || preview.errors.length > 0}
                                    className="w-full px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {executing
                                        ? selectedField === 'collections'
                                            ? `Updating ${executeProgress} of ${assetIds.length}…`
                                            : 'Applying…'
                                        : 'Confirm & Execute'}
                                </button>
                            </div>
                        )}

                        {/* Step 5: Results */}
                        {step === 5 && results && (
                            <div className="space-y-4">
                                <h4 className="text-sm font-medium text-gray-900">Results</h4>
                                <div className="space-y-4">
                                    <div className="p-4 bg-green-50 border border-green-200 rounded-md">
                                        <div className="text-sm font-medium text-green-900">
                                            {results.successes.length} assets updated successfully
                                        </div>
                                    </div>
                                    {results.failures.length > 0 && (
                                        <div className="p-4 bg-red-50 border border-red-200 rounded-md">
                                            <div className="text-sm font-medium text-red-900 mb-2">
                                                {results.failures.length} assets failed
                                            </div>
                                            <div className="space-y-1 max-h-32 overflow-y-auto">
                                                {results.failures.map((failure, idx) => (
                                                    <div key={idx} className="text-sm text-red-700">
                                                        {failure.asset_title}: {failure.error}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                                <button
                                    type="button"
                                    onClick={() => {
                                        onComplete()
                                        onClose()
                                    }}
                                    className="w-full px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                >
                                    Done
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <ConfirmDialog
                open={showCategoryChangeConfirm}
                onClose={() => { setShowCategoryChangeConfirm(false); setPendingCategoryField(null) }}
                onConfirm={() => {
                    if (pendingCategoryField) {
                        applyFieldSelect(pendingCategoryField)
                        setShowCategoryChangeConfirm(false)
                        setPendingCategoryField(null)
                    }
                }}
                title="Category change warning"
                message="Changing category may reset available metadata fields. This cannot be undone in bulk. Continue?"
                confirmText="Continue"
                cancelText="Cancel"
                variant="warning"
            />

            <CreateCollectionModal
                open={showCreateCollectionModal}
                onClose={() => setShowCreateCollectionModal(false)}
                onCreated={(newCollection) => {
                    fetchCollectionsList()
                    setSelectedCollectionIds((prev) => [...prev, newCollection.id])
                }}
            />
        </>
    )
}
