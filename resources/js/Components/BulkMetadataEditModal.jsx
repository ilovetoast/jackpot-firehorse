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
import { XMarkIcon, CheckIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline'
import MetadataFieldInput from './Upload/MetadataFieldInput'

export default function BulkMetadataEditModal({
    assetIds,
    onClose,
    onComplete,
}) {
    const [step, setStep] = useState(1) // 1: operation, 2: field, 3: value, 4: preview, 5: execute
    const [operationType, setOperationType] = useState('add') // 'add' | 'replace' | 'clear'
    const [selectedField, setSelectedField] = useState(null)
    const [value, setValue] = useState(null)
    const [preview, setPreview] = useState(null)
    const [previewToken, setPreviewToken] = useState(null)
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState(null)
    const [editableFields, setEditableFields] = useState([])
    const [executing, setExecuting] = useState(false)
    const [results, setResults] = useState(null)

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
                // Phase B2: Filter out readonly fields from bulk edit
                const editableOnly = (data.fields || []).filter(
                    field => !(field.readonly || field.population_mode === 'automatic')
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
        setSelectedField(field)
        setValue(field.current_value ?? null)
        setStep(3)
        setError(null)
    }

    // Handle preview
    const handlePreview = async () => {
        if (!selectedField || (operationType !== 'clear' && value === null)) {
            setError('Please select a field and enter a value')
            return
        }

        setLoading(true)
        setError(null)

        try {
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
        } catch (err) {
            console.error('[BulkMetadataEditModal] Preview failed', err)
            setError(err.message || 'Failed to preview changes')
        } finally {
            setLoading(false)
        }
    }

    // Handle execute
    const handleExecute = async () => {
        if (!previewToken) {
            setError('Preview token missing')
            return
        }

        setExecuting(true)
        setError(null)

        try {
            const response = await fetch('/app/assets/metadata/bulk/execute', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    preview_token: previewToken,
                }),
            })

            if (!response.ok) {
                const data = await response.json()
                throw new Error(data.message || 'Execution failed')
            }

            const data = await response.json()
            setResults(data.results)
            setStep(5)
        } catch (err) {
            console.error('[BulkMetadataEditModal] Execute failed', err)
            setError(err.message || 'Failed to execute changes')
        } finally {
            setExecuting(false)
        }
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

            {/* Modal */}
            <div
                className="fixed inset-0 z-50 flex items-center justify-center p-4"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
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
                                        onClick={() => setStep(1)}
                                        className="text-sm text-indigo-600 hover:text-indigo-700"
                                    >
                                        Back
                                    </button>
                                </div>
                                {editableFields.length === 0 ? (
                                    <div className="text-sm text-gray-500">No editable fields available</div>
                                ) : (
                                    <div className="space-y-2">
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
                                )}
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
                                                    This will clear the "{selectedField.display_label}" field for all selected assets.
                                                    Previous values will remain in the audit history.
                                                </div>
                                            </div>
                                        </div>
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
                                    disabled={loading || (operationType !== 'clear' && value === null)}
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

                                <button
                                    type="button"
                                    onClick={handleExecute}
                                    disabled={executing || preview.errors.length > 0}
                                    className="w-full px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {executing ? 'Executing...' : 'Confirm & Execute'}
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
        </>
    )
}
