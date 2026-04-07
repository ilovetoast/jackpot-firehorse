import { useState, useEffect, useCallback } from 'react'
import { router } from '@inertiajs/react'
import ManageStructureWorkspace from './ManageStructureWorkspace'
import ManageFieldsWorkspace from './ManageFieldsWorkspace'

const MANAGE_CATEGORIES_URL =
    typeof route === 'function' ? route('manage.categories') : '/app/manage/categories'

export default function ManageCategoriesHub({
    brand,
    categories = [],
    category_limits,
    canManageBrandCategories,
    canManageVisibility,
    canManageFields,
    registry,
    initial_category_slug,
    field_filter,
    low_coverage_field_keys,
    customFieldsLimit,
    metadata_field_families: metadataFieldFamiliesProp,
}) {
    const [selectedCategoryId, setSelectedCategoryId] = useState(null)

    useEffect(() => {
        if (!categories.length) return
        if (initial_category_slug) {
            const match = categories.find(
                (c) => (c.slug || '').toLowerCase() === String(initial_category_slug).toLowerCase()
            )
            setSelectedCategoryId(match ? match.id : categories[0]?.id ?? null)
            return
        }
        setSelectedCategoryId((prev) => {
            if (prev != null && categories.some((c) => c.id === prev)) return prev
            return categories[0]?.id ?? null
        })
    }, [initial_category_slug, categories])

    const handleSelectCategory = useCallback(
        (categoryId) => {
            setSelectedCategoryId(categoryId)
            const params = {}
            const cat = categoryId != null ? categories.find((c) => c.id === categoryId) : null
            if (cat?.slug) params.category = cat.slug
            if (field_filter === 'low_coverage') params.filter = 'low_coverage'
            router.get(MANAGE_CATEGORIES_URL, params, { preserveState: true, preserveScroll: true, replace: true })
        },
        [categories, field_filter]
    )

    return (
        <div className="flex flex-col gap-8 lg:flex-row lg:items-start lg:gap-6">
            <div className="shrink-0 lg:w-[min(100%,22rem)] xl:w-96 lg:sticky lg:top-4 lg:max-h-[calc(100vh-5rem)] lg:overflow-y-auto">
                <ManageStructureWorkspace
                    brand={brand}
                    categories={categories}
                    category_limits={category_limits}
                    canManageBrandCategories={canManageBrandCategories}
                    canManageVisibility={canManageVisibility}
                    canManageFields={canManageFields}
                    selectedCategoryId={selectedCategoryId}
                    onSelectCategory={handleSelectCategory}
                    managePageUrl={MANAGE_CATEGORIES_URL}
                    hubLayout
                />
            </div>
            <div className="min-w-0 flex-1">
                <ManageFieldsWorkspace
                    brand={brand}
                    categories={categories}
                    registry={registry}
                    field_filter={field_filter}
                    low_coverage_field_keys={low_coverage_field_keys}
                    canManageVisibility={canManageVisibility}
                    canManageBrandCategories={canManageBrandCategories}
                    canManageFields={canManageFields}
                    customFieldsLimit={customFieldsLimit}
                    metadataFieldFamilies={metadataFieldFamiliesProp}
                    selectedCategoryId={selectedCategoryId}
                />
            </div>
        </div>
    )
}
