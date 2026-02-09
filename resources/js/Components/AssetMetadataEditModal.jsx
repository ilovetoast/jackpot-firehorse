/**
 * Asset Metadata Edit Modal Component
 *
 * Phase 2 – Step 6: Modal for editing a single metadata field.
 */

import { useState, useEffect } from 'react'
import { XMarkIcon, LockClosedIcon } from '@heroicons/react/24/outline'
import MetadataFieldInput from './Upload/MetadataFieldInput'
import { isFieldSatisfied } from '../utils/metadataValidation'

export default function AssetMetadataEditModal({ assetId, field, onClose, onSave }) {
    const [value, setValue] = useState(field.current_value ?? null)
    const [saving, setSaving] = useState(false)
    const [error, setError] = useState(null)

    // Reset value when field changes
    useEffect(() => {
        setValue(field.current_value ?? null)
        setError(null)
    }, [field])

    // Validate value
    const isValid = () => {
        if (value === null || value === undefined || value === '') {
            // Allow empty values (user can clear field)
            return true
        }

        // For multiselect, ensure at least one value
        if (field.type === 'multiselect') {
            return Array.isArray(value) && value.length > 0
        }

        return isFieldSatisfied(field, value)
    }

    // Handle save
    const handleSave = async () => {
        if (saving) return

        // Validate
        if (!isValid()) {
            setError('Please enter a valid value')
            return
        }

        setSaving(true)
        setError(null)

        try {
            const response = await fetch(`/app/assets/${assetId}/metadata/edit`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    metadata_field_id: field.metadata_field_id,
                    value: value,
                    // Phase B5: Include override_intent for hybrid fields
                    override_intent: field.is_hybrid && field.is_overridden ? true : undefined,
                }),
            })

            if (!response.ok) {
                const data = await response.json()
                throw new Error(data.message || 'Failed to save metadata')
            }

            // Success - call onSave callback
            onSave()
        } catch (err) {
            console.error('[AssetMetadataEditModal] Failed to save', err)
            setError(err.message || 'Failed to save metadata')
        } finally {
            setSaving(false)
        }
    }

    // Handle cancel
    const handleCancel = () => {
        if (saving) return
        onClose()
    }

    return (
        <>
            {/* Backdrop */}
            <div
                className="fixed inset-0 bg-black/50 z-50 transition-opacity"
                onClick={handleCancel}
                aria-hidden="true"
            />

            {/* Modal */}
            <div
                className="fixed inset-0 z-50 flex items-center justify-center p-4"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="bg-white rounded-lg shadow-xl max-w-md w-full max-h-[90vh] overflow-y-auto">
                    {/* Header */}
                    <div className="sticky top-0 z-10 bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between">
                        <h3 className="text-lg font-semibold text-gray-900">
                            Edit {field.display_label}
                        </h3>
                        <button
                            type="button"
                            onClick={handleCancel}
                            disabled={saving}
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

                        <div className="space-y-4">
                            {field.type === 'boolean' && field.display_widget === 'toggle' ? (
                                <label className="flex items-center justify-between gap-4 cursor-pointer">
                                    <span className="text-sm font-medium text-gray-700">{field.display_label}</span>
                                    <div className="relative inline-flex items-center flex-shrink-0">
                                        <input
                                            type="checkbox"
                                            checked={value === true || value === 'true'}
                                            onChange={(e) => setValue(e.target.checked)}
                                            disabled={saving}
                                            className="sr-only peer"
                                        />
                                        <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed" />
                                    </div>
                                </label>
                            ) : (
                                <MetadataFieldInput
                                    field={field}
                                    value={value}
                                    onChange={setValue}
                                    disabled={saving}
                                    showError={false}
                                    isUploadContext={false}
                                />
                            )}
                        </div>
                    </div>

                    {/* Footer */}
                    <div className="sticky bottom-0 bg-white border-t border-gray-200 px-6 py-4 flex items-center justify-end gap-3">
                        <button
                            type="button"
                            onClick={handleCancel}
                            disabled={saving}
                            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={handleSave}
                            disabled={saving || !isValid() || field.readonly || field.population_mode === 'automatic'}
                            className="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {saving ? 'Saving...' : 'Save'}
                        </button>
                    </div>
                </div>
            </div>
        </>
    )
}
