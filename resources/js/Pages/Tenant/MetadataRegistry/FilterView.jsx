/**
 * Filter View Component
 * 
 * Phase G.3: Tenant Filter Surface Control
 * 
 * Displays all metadata fields (including automated) with filter visibility toggles.
 * This view is independent from category enablement and upload/edit visibility.
 */

import { useState } from 'react'
import { router } from '@inertiajs/react'
import {
    FunnelIcon,
    InformationCircleIcon,
} from '@heroicons/react/24/outline'

export default function FilterView({ 
    registry, 
    canManageVisibility 
}) {
    const { system_fields: systemFields = [], tenant_fields = [] } = registry || {}
    const allFields = [...systemFields, ...tenant_fields]
    
    // Phase G.3: Include ALL fields for filter control, including automated ones
    // Filter out only fields where show_in_filters is false at system level
    const filterableFields = allFields.filter(field => {
        // Exclude fields that are explicitly marked as not filterable at system level
        return field.show_in_filters !== false
    })
    
    const handleFilterVisibilityToggle = async (fieldId, currentValue) => {
        if (!canManageVisibility) return

        const newValue = !currentValue

        try {
            await router.post(`/api/tenant/metadata/fields/${fieldId}/visibility`, {
                show_in_filters: newValue,
            }, {
                preserveScroll: true,
                onSuccess: () => {
                    router.reload({ only: ['registry'] })
                },
            })
        } catch (error) {
            console.error('Failed to update filter visibility:', error)
        }
    }

    const getPopulationModeBadge = (field) => {
        const mode = field.population_mode || 'manual'
        const isReadonly = field.readonly === true
        
        if (mode === 'automatic' && isReadonly) {
            return (
                <span className="px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-600 rounded">
                    Auto
                </span>
            )
        } else if (mode === 'hybrid') {
            return (
                <span className="px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-600 rounded">
                    Hybrid
                </span>
            )
        } else {
            return (
                <span className="px-2 py-0.5 text-xs font-medium bg-green-100 text-green-600 rounded">
                    Manual
                </span>
            )
        }
    }

    return (
        <div className="px-6 py-4 space-y-6">
            {/* Header */}
            <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <div className="flex items-start gap-3">
                    <FunnelIcon className="w-5 h-5 text-blue-600 mt-0.5 flex-shrink-0" />
                    <div className="flex-1">
                        <h3 className="text-sm font-semibold text-blue-900 mb-1">
                            Filter Surface Control
                        </h3>
                        <p className="text-sm text-blue-800">
                            Control which metadata fields appear as filter options in the asset grid.
                            This does not affect how metadata is populated or displayed elsewhere.
                        </p>
                    </div>
                </div>
            </div>

            {/* Helper Text */}
            <div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <div className="flex items-start gap-2">
                    <InformationCircleIcon className="w-5 h-5 text-gray-600 mt-0.5 flex-shrink-0" />
                    <div className="text-sm text-gray-700">
                        <p className="mb-2">
                            <strong>What this controls:</strong> Which fields appear as filter options when browsing assets.
                        </p>
                        <p className="mb-2">
                            <strong>What this does NOT affect:</strong>
                        </p>
                        <ul className="list-disc list-inside space-y-1 ml-4">
                            <li>How metadata is populated (automated fields remain automated)</li>
                            <li>Upload and edit forms (controlled separately)</li>
                            <li>Asset detail displays</li>
                            <li>Category enablement</li>
                        </ul>
                    </div>
                </div>
            </div>

            {/* Fields List */}
            {filterableFields.length === 0 ? (
                <div className="text-center py-12">
                    <p className="text-sm text-gray-500">
                        No filterable fields found.
                    </p>
                </div>
            ) : (
                <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Field
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Type
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Population
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Available in Filters
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-gray-200">
                                {filterableFields.map(field => {
                                    const isSystem = systemFields.some(sf => sf.id === field.id)
                                    const effectiveFilter = field.effective_show_in_filters ?? field.show_in_filters ?? true
                                    
                                    return (
                                        <tr key={field.id} className="hover:bg-gray-50">
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm font-medium text-gray-900">
                                                        {field.label}
                                                    </span>
                                                    {isSystem && (
                                                        <span className="px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-800 rounded">
                                                            System
                                                        </span>
                                                    )}
                                                    {!isSystem && (
                                                        <span className="px-2 py-0.5 text-xs font-medium bg-purple-100 text-purple-800 rounded">
                                                            Custom
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <span className="text-sm text-gray-600">
                                                    {field.field_type || 'text'}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                {getPopulationModeBadge(field)}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <label className="flex items-center gap-2 cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        checked={effectiveFilter}
                                                        onChange={() => handleFilterVisibilityToggle(field.id, effectiveFilter)}
                                                        disabled={!canManageVisibility}
                                                        className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                                    />
                                                    <span className="text-sm text-gray-700">
                                                        {effectiveFilter ? 'Shown' : 'Hidden'}
                                                    </span>
                                                </label>
                                            </td>
                                        </tr>
                                    )
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    )
}
