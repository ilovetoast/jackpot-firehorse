/**
 * Asset Metadata Edit Form Component
 * 
 * Shared component for editing asset metadata fields.
 * Can be used in upload, review, and other contexts.
 * 
 * Features:
 * - Fetches metadata schema based on asset category
 * - Renders proper field types (select, date, text, etc.)
 * - Filters to user-defined fields only (excludes automatic/system)
 * - Uses MetadataFieldInput for consistent field rendering
 */

import { useState, useEffect } from 'react'
import { usePage } from '@inertiajs/react'
import MetadataGroups from './Upload/MetadataGroups'
import MetadataFieldInput from './Upload/MetadataFieldInput'

export default function AssetMetadataEditForm({ 
    asset, 
    categoryId = null,
    assetType = 'image',
    values = {},
    onChange,
    disabled = false,
    showErrors = false,
    filterUserDefinedOnly = true
}) {
    const { auth } = usePage().props
    const [metadataSchema, setMetadataSchema] = useState(null)
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)
    
    // Get category ID from asset if not provided
    const resolvedCategoryId = categoryId || asset?.metadata?.category_id || asset?.category_id
    
    // Determine asset type from mime_type if not provided
    const determineAssetType = () => {
        if (assetType) return assetType
        if (!asset?.mime_type) return 'image' // Default
        
        const mime = asset.mime_type.toLowerCase()
        if (mime.startsWith('video/')) return 'video'
        if (mime.startsWith('image/')) return 'image'
        if (mime.includes('pdf') || mime.includes('document') || mime.includes('text')) return 'document'
        return 'image' // Default fallback
    }
    
    const resolvedAssetType = determineAssetType()
    
    // Fetch metadata schema
    useEffect(() => {
        if (!resolvedCategoryId) {
            setMetadataSchema(null)
            setLoading(false)
            return
        }
        
        setLoading(true)
        setError(null)
        
        const params = new URLSearchParams({
            category_id: resolvedCategoryId.toString(),
            asset_type: resolvedAssetType,
        })
        
        fetch(`/app/uploads/metadata-schema?${params.toString()}`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            credentials: 'same-origin',
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Failed to fetch metadata schema: ${response.status}`)
                }
                return response.json()
            })
            .then(data => {
                if (data.error) {
                    throw new Error(data.message || 'Failed to load metadata schema')
                }
                
                // Note: UploadMetadataSchemaResolver already filters out automatic fields,
                // but we apply additional filtering here to be safe
                if (filterUserDefinedOnly && data.groups) {
                    const filteredGroups = data.groups.map(group => ({
                        ...group,
                        fields: (group.fields || []).filter(field => {
                            // Exclude automatic/system fields (upload schema already does this, but double-check)
                            const isAutomatic = field.population_mode === 'automatic'
                            // Note: source might not be in upload schema, so we rely on population_mode
                            return !isAutomatic
                        })
                    })).filter(group => group.fields && group.fields.length > 0)
                    
                    setMetadataSchema({
                        ...data,
                        groups: filteredGroups
                    })
                } else {
                    setMetadataSchema(data)
                }
                
                setLoading(false)
            })
            .catch(err => {
                console.error('[AssetMetadataEditForm] Failed to fetch metadata schema', err)
                setError(err.message)
                setLoading(false)
            })
    }, [resolvedCategoryId, resolvedAssetType, filterUserDefinedOnly])
    
    // Handle field value changes
    const handleFieldChange = (fieldKey, value) => {
        if (onChange) {
            onChange(fieldKey, value)
        }
    }
    
    if (loading) {
        return (
            <div className="text-center py-4">
                <div className="text-sm text-gray-500">Loading metadata fields...</div>
            </div>
        )
    }
    
    if (error) {
        return (
            <div className="rounded-md bg-red-50 border border-red-200 p-4">
                <p className="text-sm text-red-800">Failed to load metadata fields: {error}</p>
            </div>
        )
    }
    
    if (!metadataSchema || !metadataSchema.groups || metadataSchema.groups.length === 0) {
        return (
            <div className="rounded-md bg-gray-50 border border-gray-200 p-4">
                <p className="text-sm text-gray-600">No metadata fields available for this asset.</p>
            </div>
        )
    }
    
    return (
        <MetadataGroups
            groups={metadataSchema.groups}
            values={values}
            onChange={handleFieldChange}
            disabled={disabled}
            showErrors={showErrors}
        />
    )
}
