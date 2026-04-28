import { useState, useEffect, useCallback, useRef } from 'react'
import { CheckCircleIcon, ExclamationCircleIcon } from '@heroicons/react/24/outline'
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
    const [pageNotice, setPageNotice] = useState(null)
    const noticeClearRef = useRef(null)

    const showPageNotice = useCallback((text, options = {}) => {
        const variant = options.variant ?? 'success'
        const durationMs = options.durationMs ?? (variant === 'error' ? 6000 : 4000)
        if (noticeClearRef.current) {
            clearTimeout(noticeClearRef.current)
        }
        setPageNotice({ text, variant })
        noticeClearRef.current = setTimeout(() => {
            setPageNotice(null)
            noticeClearRef.current = null
        }, durationMs)
    }, [])

    useEffect(() => {
        if (!categories.length) return
        if (typeof window !== 'undefined') {
            const urlSlug = new URLSearchParams(window.location.search).get('category')
            if (urlSlug) {
                const match = categories.find(
                    (c) => (c.slug || '').toLowerCase() === urlSlug.toLowerCase()
                )
                if (match) {
                    setSelectedCategoryId(match.id)
                    return
                }
            }
        }
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

    useEffect(() => {
        if (typeof window === 'undefined' || !categories.length) return
        const onPopState = () => {
            const urlSlug = new URLSearchParams(window.location.search).get('category')
            if (urlSlug) {
                const match = categories.find(
                    (c) => (c.slug || '').toLowerCase() === urlSlug.toLowerCase()
                )
                if (match) {
                    setSelectedCategoryId(match.id)
                    return
                }
            }
            if (initial_category_slug) {
                const m = categories.find(
                    (c) => (c.slug || '').toLowerCase() === String(initial_category_slug).toLowerCase()
                )
                setSelectedCategoryId(m ? m.id : categories[0]?.id ?? null)
                return
            }
            setSelectedCategoryId(categories[0]?.id ?? null)
        }
        window.addEventListener('popstate', onPopState)
        return () => window.removeEventListener('popstate', onPopState)
    }, [categories, initial_category_slug])

    const handleSelectCategory = useCallback(
        (categoryId) => {
            setSelectedCategoryId(categoryId)
            if (typeof window === 'undefined') return
            const cat = categoryId != null ? categories.find((c) => c.id === categoryId) : null
            const u = new URL(window.location.href)
            if (cat?.slug) u.searchParams.set('category', cat.slug)
            else u.searchParams.delete('category')
            if (field_filter === 'low_coverage') u.searchParams.set('filter', 'low_coverage')
            else u.searchParams.delete('filter')
            const next = `${u.pathname}${u.search}${u.hash}`
            window.history.replaceState(window.history.state, '', next)
        },
        [categories, field_filter]
    )

    return (
        <div className="mx-auto flex w-full max-w-7xl flex-col gap-6">
            {pageNotice ? (
                <div
                    role="status"
                    aria-live="polite"
                    className={`flex items-start gap-3 rounded-lg border px-4 py-3 text-sm shadow-sm ${
                        pageNotice.variant === 'error'
                            ? 'border-red-200 bg-red-50 text-red-900'
                            : 'border-emerald-200 bg-emerald-50 text-emerald-950'
                    }`}
                >
                    {pageNotice.variant === 'error' ? (
                        <ExclamationCircleIcon className="h-5 w-5 shrink-0 text-red-600" aria-hidden />
                    ) : (
                        <CheckCircleIcon className="h-5 w-5 shrink-0 text-emerald-600" aria-hidden />
                    )}
                    <span className="min-w-0 pt-0.5 font-medium leading-snug">{pageNotice.text}</span>
                </div>
            ) : null}

            <div className="flex flex-col gap-8 lg:flex-row lg:items-start lg:gap-10 xl:gap-12">
                <div className="min-h-0 w-full shrink-0 lg:sticky lg:top-4 lg:w-[min(100%,25rem)] lg:max-h-[calc(100vh-5rem)] lg:overflow-y-auto xl:w-[25rem]">
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
                        onSaveNotice={showPageNotice}
                    />
                </div>
                <div className="min-w-0 w-full flex-1 lg:min-w-0 lg:max-w-[42rem] xl:max-w-[44rem]">
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
                        onSaveNotice={showPageNotice}
                    />
                </div>
            </div>
        </div>
    )
}
