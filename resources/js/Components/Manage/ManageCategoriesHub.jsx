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
        if (!categories.length) {
            setSelectedCategoryId(null)
            return
        }
        const slugFromUrl =
            typeof window !== 'undefined'
                ? new URLSearchParams(window.location.search).get('category')
                : null
        const slug = slugFromUrl ?? initial_category_slug ?? null
        if (!slug) {
            setSelectedCategoryId(null)
            return
        }
        const match = categories.find(
            (c) => (c.slug || '').toLowerCase() === String(slug).toLowerCase()
        )
        setSelectedCategoryId(match ? match.id : null)
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
            setSelectedCategoryId(null)
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
        <div className="mx-auto flex w-full max-w-none flex-col gap-5">
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

            <div className="flex min-h-[min(70vh,720px)] flex-col overflow-hidden rounded-xl border border-slate-200/90 bg-white shadow-sm lg:min-h-0 lg:flex-row lg:items-stretch lg:overflow-visible">
                <aside className="flex min-h-0 w-full shrink-0 flex-col border-b border-slate-200/90 bg-slate-50/90 lg:max-h-[min(calc(100vh-10rem),1200px)] lg:w-[300px] lg:min-w-[280px] lg:max-w-[320px] lg:border-b-0 lg:border-r lg:border-slate-200/90 lg:overflow-y-auto">
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
                </aside>
                <div className="flex min-w-0 flex-1 flex-col bg-white lg:min-h-0">
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
                            onSaveNotice={showPageNotice}
                            hubEmbedded
                        />
                    </div>
                </div>
            </div>
        </div>
    )
}
