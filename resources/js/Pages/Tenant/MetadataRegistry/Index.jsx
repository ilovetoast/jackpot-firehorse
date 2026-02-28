import { useState, useMemo, useEffect } from 'react'
import { router } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppHead from '../../../Components/AppHead'
import ByCategoryView from './ByCategory'

/**
 * Categories & Fields (Metadata Management) Index
 *
 * Category-driven only. No top-level tabs.
 * Left sidebar controls active category; all field views are per-category.
 */
export default function TenantMetadataRegistryIndex({
    registry,
    brands = [],
    active_brand_id = null,
    categories,
    canManageVisibility,
    canManageFields,
    customFieldsLimit = null,
    metadata_field_families = {},
    initial_category_slug = null,
    initial_brand_id = null,
}) {
    const brandIds = useMemo(() => brands.map((b) => b.id), [brands])
    const initialBrandId =
        (initial_brand_id != null && brandIds.includes(initial_brand_id))
            ? initial_brand_id
            : (active_brand_id != null && brandIds.includes(active_brand_id))
                ? active_brand_id
                : brands[0]?.id ?? null
    const [selectedBrandId, setSelectedBrandId] = useState(initialBrandId)

    useEffect(() => {
        if (brands.length === 0) return
        const ids = brands.map((b) => b.id)
        if (selectedBrandId !== null && !ids.includes(selectedBrandId)) {
            setSelectedBrandId(
                initialBrandId != null && ids.includes(initialBrandId)
                    ? initialBrandId
                    : brands[0].id
            )
        }
    }, [brands, selectedBrandId, initialBrandId])

    // Sync brand from URL when it changes (e.g. after router.get)
    useEffect(() => {
        if (initial_brand_id != null && brandIds.includes(initial_brand_id)) {
            setSelectedBrandId(initial_brand_id)
        }
    }, [initial_brand_id, brandIds])

    return (
        <div className="min-h-screen bg-gray-50">
            <AppHead title="Categories & Fields" />
            <AppNav />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                {/* Page Header */}
                <div className="mb-6 flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-gray-900">
                            Categories & Fields
                        </h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Define how your brand content is organized and behaves.
                        </p>
                    </div>
                    {customFieldsLimit && customFieldsLimit.max > 0 && (
                        <span
                            className={`flex-shrink-0 text-xs uppercase tracking-wide ${
                                customFieldsLimit.current / customFieldsLimit.max >= 0.95
                                    ? 'text-red-600 rounded-full px-2.5 py-0.5 bg-red-50'
                                    : customFieldsLimit.current / customFieldsLimit.max >= 0.8
                                        ? 'text-amber-600 rounded-full px-2.5 py-0.5 bg-amber-50'
                                        : 'text-gray-500'
                            }`}
                        >
                            Custom Fields {customFieldsLimit.current} / {customFieldsLimit.max}
                        </span>
                    )}
                </div>

                <ByCategoryView
                    registry={registry}
                    brands={brands}
                    selectedBrandId={selectedBrandId}
                    onBrandChange={setSelectedBrandId}
                    categories={categories}
                    canManageVisibility={canManageVisibility}
                    canManageFields={canManageFields}
                    customFieldsLimit={customFieldsLimit}
                    metadataFieldFamilies={metadata_field_families}
                    initialCategorySlug={initial_category_slug}
                />
            </div>
        </div>
    )
}
