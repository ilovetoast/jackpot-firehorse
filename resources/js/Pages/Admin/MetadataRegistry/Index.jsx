import { Link, router, usePage } from '@inertiajs/react'
import { usePermission } from '../../../hooks/usePermission'
import { useMemo, useState, useCallback } from 'react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import {
    InformationCircleIcon,
    SparklesIcon,
    FunnelIcon,
    LockClosedIcon,
    PencilSquareIcon,
    EyeIcon,
    EyeSlashIcon,
    XMarkIcon,
    AdjustmentsHorizontalIcon,
    ChevronDownIcon,
    ChevronRightIcon,
    MagnifyingGlassIcon,
    PlusIcon,
} from '@heroicons/react/24/outline'

const ADMIN_SYSTEM_CATEGORIES_URL =
    typeof route === 'function' ? route('admin.system-categories.index') : '/app/admin/system-categories'

function bundleLink(templateId) {
    return `${ADMIN_SYSTEM_CATEGORIES_URL}?openBundle=${templateId}`
}

const METADATA_FIELD_STORE_URL =
    typeof route === 'function' ? route('admin.metadata.fields.store') : '/app/admin/metadata/fields'

function groupFieldsByType(fieldList) {
    const byType = new Map()
    for (const f of fieldList) {
        const t = (f.field_type || 'other').toString()
        if (!byType.has(t)) byType.set(t, [])
        byType.get(t).push(f)
    }
    return [...byType.entries()].sort((a, b) => a[0].localeCompare(b[0]))
}

const ADD_FIELD_INITIAL = {
    key: '',
    system_label: '',
    type: 'text',
    applies_to: 'all',
    population_mode: 'manual',
    show_on_upload: true,
    show_on_edit: true,
    show_in_filters: true,
    readonly: false,
    group_key: 'custom',
}

export default function MetadataRegistryIndex({ fields = [], latestSystemTemplates = [] }) {
    const { auth, flash } = usePage().props
    const { can } = usePermission()
    const [categoryModalOpen, setCategoryModalOpen] = useState(false)
    const [selectedField, setSelectedField] = useState(null)
    const [categories, setCategories] = useState([])
    const [loadingCategories, setLoadingCategories] = useState(false)
    const [saving, setSaving] = useState(false)

    const [search, setSearch] = useState('')
    const [filterType, setFilterType] = useState('')
    const [filterApplies, setFilterApplies] = useState('')
    const [filterPopulation, setFilterPopulation] = useState('')
    const [filterTemplateId, setFilterTemplateId] = useState('')
    const [collapsedTypes, setCollapsedTypes] = useState(() => ({}))

    const canManageVisibility = can('metadata.system.visibility.manage')
    const canManageFields = can('metadata.system.fields.manage')

    const [addModalOpen, setAddModalOpen] = useState(false)
    const [addFieldForm, setAddFieldForm] = useState(() => ({ ...ADD_FIELD_INITIAL }))
    const [addOptions, setAddOptions] = useState([{ value: '', label: '' }])
    const [selectedTemplateIds, setSelectedTemplateIds] = useState(() => new Set())
    const [addFieldErrors, setAddFieldErrors] = useState({})
    const [addFieldProcessing, setAddFieldProcessing] = useState(false)

    const toggleTemplateSelected = useCallback((id) => {
        setSelectedTemplateIds((prev) => {
            const next = new Set(prev)
            if (next.has(id)) next.delete(id)
            else next.add(id)
            return next
        })
    }, [])

    const closeAddModal = useCallback(() => {
        setAddModalOpen(false)
        setAddFieldForm({ ...ADD_FIELD_INITIAL })
        setAddOptions([{ value: '', label: '' }])
        setSelectedTemplateIds(new Set())
        setAddFieldErrors({})
    }, [])

    const submitAddField = useCallback(
        (e) => {
            e.preventDefault()
            setAddFieldErrors({})
            const type = addFieldForm.type
            let options =
                type === 'select' || type === 'multiselect'
                    ? addOptions.map((o) => ({ value: o.value.trim(), label: o.label.trim() })).filter((o) => o.value && o.label)
                    : undefined
            if ((type === 'select' || type === 'multiselect') && options.length === 0) {
                setAddFieldErrors({ options: 'Add at least one option with value and label.' })
                return
            }
            const template_defaults = [...selectedTemplateIds].map((system_category_id) => ({
                system_category_id,
                is_hidden: false,
                is_upload_hidden: false,
                is_filter_hidden: false,
                is_edit_hidden: false,
            }))
            const payload = {
                key: addFieldForm.key.trim(),
                system_label: addFieldForm.system_label.trim(),
                type,
                applies_to: addFieldForm.applies_to,
                population_mode: addFieldForm.population_mode,
                show_on_upload: !!addFieldForm.show_on_upload,
                show_on_edit: !!addFieldForm.show_on_edit,
                show_in_filters: !!addFieldForm.show_in_filters,
                readonly: !!addFieldForm.readonly,
                group_key: addFieldForm.group_key?.trim() || 'custom',
            }
            if (options) payload.options = options
            if (template_defaults.length > 0) payload.template_defaults = template_defaults

            setAddFieldProcessing(true)
            router.post(METADATA_FIELD_STORE_URL, payload, {
                preserveScroll: true,
                onFinish: () => setAddFieldProcessing(false),
                onSuccess: () => closeAddModal(),
                onError: (errs) => setAddFieldErrors(errs || {}),
            })
        },
        [addFieldForm, addOptions, selectedTemplateIds, closeAddModal]
    )

    const allTypes = useMemo(() => {
        const s = new Set(fields.map((f) => (f.field_type || 'other').toString()))
        return [...s].sort()
    }, [fields])

    const templateChoices = useMemo(() => {
        const m = new Map()
        for (const f of fields) {
            for (const t of f.default_bundle_templates || []) {
                if (!m.has(t.id)) m.set(t.id, t)
            }
        }
        return [...m.values()].sort((a, b) => a.name.localeCompare(b.name))
    }, [fields])

    const filteredFields = useMemo(() => {
        const q = search.trim().toLowerCase()
        return fields.filter((f) => {
            if (q && !(`${f.key} ${f.label}`.toLowerCase().includes(q))) return false
            if (filterType && (f.field_type || '') !== filterType) return false
            if (filterApplies && (f.applies_to || '') !== filterApplies) return false
            if (filterPopulation && (f.population_mode || '') !== filterPopulation) return false
            if (filterTemplateId) {
                const id = Number(filterTemplateId)
                const list = f.default_bundle_templates || []
                if (!list.some((t) => t.id === id)) return false
            }
            return true
        })
    }, [fields, search, filterType, filterApplies, filterPopulation, filterTemplateId])

    const groupedFiltered = useMemo(() => groupFieldsByType(filteredFields), [filteredFields])

    const toggleTypeCollapsed = useCallback((typeKey) => {
        setCollapsedTypes((prev) => ({ ...prev, [typeKey]: !prev[typeKey] }))
    }, [])

    const getPopulationModeBadge = (mode) => {
        const colors = {
            manual: 'bg-blue-100 text-blue-800',
            automatic: 'bg-green-100 text-green-800',
            hybrid: 'bg-purple-100 text-purple-800',
        }
        return colors[mode] || 'bg-gray-100 text-gray-800'
    }

    const getTypeBadge = (type) => (
        <span className="inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10">
            {type}
        </span>
    )

    const getAppliesToBadge = (appliesTo) => {
        const colors = {
            all: 'bg-indigo-100 text-indigo-800',
            image: 'bg-pink-100 text-pink-800',
            video: 'bg-red-100 text-red-800',
            document: 'bg-yellow-100 text-yellow-800',
        }
        const color = colors[appliesTo] || 'bg-gray-100 text-gray-800'
        return (
            <span
                className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset ring-gray-500/10 ${color}`}
            >
                {appliesTo}
            </span>
        )
    }

    const openCategoryModal = async (field) => {
        setSelectedField(field)
        setLoadingCategories(true)
        setCategoryModalOpen(true)
        try {
            const response = await fetch(`/app/admin/metadata/fields/${field.id}/categories`)
            const data = await response.json()
            setCategories(data.categories || [])
        } catch (error) {
            console.error('Failed to load categories:', error)
            setCategories([])
        } finally {
            setLoadingCategories(false)
        }
    }

    const toggleCategorySuppression = async (categoryId) => {
        if (!selectedField || saving) return
        const category = categories.find((c) => c.id === categoryId)
        if (!category) return
        setSaving(true)
        try {
            if (category.is_suppressed) {
                const response = await fetch(
                    `/app/admin/metadata/fields/${selectedField.id}/categories/${categoryId}/suppress`,
                    { method: 'DELETE' }
                )
                if (response.ok) {
                    setCategories(
                        categories.map((c) =>
                            c.id === categoryId ? { ...c, is_suppressed: false, is_visible: true } : c
                        )
                    )
                }
            } else {
                const response = await fetch(
                    `/app/admin/metadata/fields/${selectedField.id}/categories/${categoryId}/suppress`,
                    { method: 'POST' }
                )
                if (response.ok) {
                    setCategories(
                        categories.map((c) =>
                            c.id === categoryId ? { ...c, is_suppressed: true, is_visible: false } : c
                        )
                    )
                }
            }
        } catch (error) {
            console.error('Failed to toggle suppression:', error)
            alert('Failed to update category visibility')
        } finally {
            setSaving(false)
        }
    }

    const closeCategoryModal = () => {
        setCategoryModalOpen(false)
        setSelectedField(null)
        setCategories([])
    }

    const inBundleCount = useMemo(
        () => fields.filter((f) => (f.in_default_bundle_count || 0) > 0).length,
        [fields]
    )

    const withOptionsCount = useMemo(() => fields.filter((f) => (f.system_options_count || 0) > 0).length, [fields])

    const renderFieldRow = (field) => (
        <tr key={field.id} className="hover:bg-gray-50">
            <td className="whitespace-nowrap py-3 pl-4 pr-3 text-sm sm:pl-6">
                <div>
                    <div className="mb-1 font-mono text-xs text-gray-500">{field.key}</div>
                    <div className="font-medium text-gray-900">{field.label}</div>
                    <div className="mt-1">{getAppliesToBadge(field.applies_to)}</div>
                </div>
            </td>
            <td className="whitespace-nowrap px-3 py-3 text-sm text-gray-500">{getTypeBadge(field.field_type)}</td>
            <td className="whitespace-nowrap px-3 py-3 text-sm">
                <span
                    className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${getPopulationModeBadge(field.population_mode)}`}
                >
                    {field.population_mode}
                </span>
            </td>
            <td className="whitespace-nowrap px-3 py-3 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                    {field.show_on_upload ? (
                        <span className="inline-flex items-center gap-1 text-green-600" title="Visible on Upload">
                            <EyeIcon className="h-4 w-4" />
                            <span className="text-xs">Upload</span>
                        </span>
                    ) : (
                        <span className="inline-flex items-center gap-1 text-gray-400" title="Hidden on Upload">
                            <EyeSlashIcon className="h-4 w-4" />
                            <span className="text-xs">Upload</span>
                        </span>
                    )}
                    {field.show_on_edit ? (
                        <span className="inline-flex items-center gap-1 text-green-600" title="Visible on Edit">
                            <PencilSquareIcon className="h-4 w-4" />
                            <span className="text-xs">Edit</span>
                        </span>
                    ) : (
                        <span className="inline-flex items-center gap-1 text-gray-400" title="Hidden on Edit">
                            <EyeSlashIcon className="h-4 w-4" />
                            <span className="text-xs">Edit</span>
                        </span>
                    )}
                    {field.show_in_filters ? (
                        <span className="inline-flex items-center gap-1 text-green-600" title="Visible in Filters">
                            <FunnelIcon className="h-4 w-4" />
                            <span className="text-xs">Filter</span>
                        </span>
                    ) : (
                        <span className="inline-flex items-center gap-1 text-gray-400" title="Hidden in Filters">
                            <EyeSlashIcon className="h-4 w-4" />
                            <span className="text-xs">Filter</span>
                        </span>
                    )}
                </div>
            </td>
            <td className="px-3 py-3 text-sm">
                <div className="flex flex-wrap gap-1">
                    {field.is_ai_related && (
                        <span
                            className="inline-flex items-center gap-1 rounded-md bg-purple-100 px-2 py-1 text-xs font-medium text-purple-800 ring-1 ring-inset ring-purple-500/10"
                            title="Has AI-generated candidates"
                        >
                            <SparklesIcon className="h-3 w-3" />
                            AI
                        </span>
                    )}
                    {field.is_filter_only && (
                        <span
                            className="inline-flex items-center gap-1 rounded-md bg-orange-100 px-2 py-1 text-xs font-medium text-orange-800 ring-1 ring-inset ring-orange-500/10"
                            title="Filter-only field"
                        >
                            <FunnelIcon className="h-3 w-3" />
                            Filter-Only
                        </span>
                    )}
                    {field.readonly && (
                        <span
                            className="inline-flex items-center gap-1 rounded-md bg-red-100 px-2 py-1 text-xs font-medium text-red-800 ring-1 ring-inset ring-red-500/10"
                            title="Read-only field"
                        >
                            <LockClosedIcon className="h-3 w-3" />
                            Read-Only
                        </span>
                    )}
                    {field.supports_override && (
                        <span
                            className="inline-flex items-center rounded-md bg-blue-100 px-2 py-1 text-xs font-medium text-blue-800 ring-1 ring-inset ring-blue-500/10"
                            title="Supports user override"
                        >
                            Override
                        </span>
                    )}
                    {field.is_internal_only && (
                        <span
                            className="inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-800 ring-1 ring-inset ring-gray-500/10"
                            title="Internal-only field"
                        >
                            Internal
                        </span>
                    )}
                    {(field.system_options_count || 0) > 0 && (
                        <span
                            className="inline-flex items-center rounded-md bg-sky-100 px-2 py-1 text-xs font-medium text-sky-900 ring-1 ring-inset ring-sky-500/20"
                            title="System select options"
                        >
                            {field.system_options_count} options
                        </span>
                    )}
                </div>
            </td>
            <td className="whitespace-nowrap px-3 py-3 text-sm text-gray-500">
                <div className="space-y-1">
                    <div>
                        <span className="font-medium text-gray-900">
                            {field.total_assets_with_value.toLocaleString()}
                        </span>
                        <span className="text-xs text-gray-500"> assets</span>
                    </div>
                    <div className="text-xs">
                        <span className="text-gray-600">{field.percent_populated}%</span>
                        <span className="text-gray-500"> populated</span>
                    </div>
                    {field.supports_override && (
                        <div className="text-xs">
                            <span className="text-gray-600">{field.percent_user_override}%</span>
                            <span className="text-gray-500"> overridden</span>
                        </div>
                    )}
                    {field.pending_review_count > 0 && (
                        <div className="text-xs text-orange-600">{field.pending_review_count} pending review</div>
                    )}
                </div>
            </td>
            <td className="max-w-[14rem] px-3 py-3 text-sm">
                {(field.default_bundle_templates || []).length === 0 ? (
                    <span className="text-xs text-gray-400">Config fallback only</span>
                ) : (
                    <div className="flex flex-wrap gap-1">
                        {(field.default_bundle_templates || []).map((t) => (
                            <Link
                                key={t.id}
                                href={bundleLink(t.id)}
                                className="inline-flex max-w-full items-center rounded-md bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-800 ring-1 ring-indigo-200 hover:bg-indigo-100"
                                title={`${t.slug} (${t.asset_type})`}
                            >
                                <span className="truncate">{t.name}</span>
                            </Link>
                        ))}
                    </div>
                )}
            </td>
            {canManageVisibility && (
                <td className="whitespace-nowrap px-3 py-3 text-sm">
                    <button
                        type="button"
                        onClick={() => openCategoryModal(field)}
                        className="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100"
                        title="Manage category visibility"
                    >
                        <AdjustmentsHorizontalIcon className="h-3 w-3" />
                        Categories
                    </button>
                </td>
            )}
        </tr>
    )

    return (
        <div className="min-h-full">
            <AppNav brand={auth?.activeBrand ?? null} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                    <div className="mb-8">
                        <div className="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2">
                            <Link
                                href="/app/admin"
                                className="inline-block text-sm font-medium text-gray-500 hover:text-gray-700"
                            >
                                ← Back to Admin Dashboard
                            </Link>
                            <Link
                                href={ADMIN_SYSTEM_CATEGORIES_URL}
                                className="inline-block text-sm font-medium text-indigo-600 hover:text-indigo-800"
                            >
                                System categories &amp; field bundles →
                            </Link>
                        </div>
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <h1 className="text-3xl font-bold tracking-tight text-gray-900">System Metadata Registry</h1>
                                <p className="mt-2 text-sm text-gray-700">
                                    Secondary, field-centric view of all system metadata fields. Prefer{' '}
                                    <Link
                                        href={ADMIN_SYSTEM_CATEGORIES_URL}
                                        className="font-medium text-indigo-600 hover:text-indigo-800"
                                    >
                                        System categories
                                    </Link>{' '}
                                    to edit default field bundles per template.
                                </p>
                            </div>
                            {canManageFields && (
                                <button
                                    type="button"
                                    onClick={() => setAddModalOpen(true)}
                                    className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                >
                                    <PlusIcon className="h-5 w-5" />
                                    Add field
                                </button>
                            )}
                        </div>
                    </div>

                    {flash?.success && (
                        <div className="mb-6 rounded-md border border-green-200 bg-green-50 p-4 text-sm text-green-900">
                            {flash.success}
                        </div>
                    )}

                    <div className="mb-6 rounded-md border border-blue-200 bg-blue-50 p-4">
                        <div className="flex">
                            <InformationCircleIcon className="h-5 w-5 flex-shrink-0 text-blue-600" />
                            <div className="ml-3">
                                <h3 className="text-sm font-medium text-blue-800">
                                    {canManageVisibility ? 'System Metadata Governance' : 'Observability Only'}
                                </h3>
                                <div className="mt-2 text-sm text-blue-700">
                                    <p>
                                        {canManageVisibility
                                            ? 'Use filters and type groups below for metrics and global suppression. Default bundles: chips open System categories with the bundle editor for that template.'
                                            : 'This registry provides read-only inspection. No mutations in this view.'}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                        <div className="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                            <p className="text-sm font-medium text-gray-500">Total fields</p>
                            <p className="mt-1 text-2xl font-semibold text-gray-900">{fields.length}</p>
                        </div>
                        <div className="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                            <p className="text-sm font-medium text-gray-500">In bundle (DB)</p>
                            <p className="mt-1 text-2xl font-semibold text-gray-900">{inBundleCount}</p>
                            <p className="mt-1 text-xs text-gray-500">Templates with saved defaults</p>
                        </div>
                        <div className="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                            <p className="text-sm font-medium text-gray-500">AI-related</p>
                            <p className="mt-1 text-2xl font-semibold text-gray-900">
                                {fields.filter((f) => f.is_ai_related).length}
                            </p>
                        </div>
                        <div className="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                            <p className="text-sm font-medium text-gray-500">System options</p>
                            <p className="mt-1 text-2xl font-semibold text-gray-900">{withOptionsCount}</p>
                            <p className="mt-1 text-xs text-gray-500">Fields with system select options</p>
                        </div>
                        <div className="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                            <p className="text-sm font-medium text-gray-500">Filter-only</p>
                            <p className="mt-1 text-2xl font-semibold text-gray-900">
                                {fields.filter((f) => f.is_filter_only).length}
                            </p>
                        </div>
                    </div>

                    <div className="mb-4 flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm ring-1 ring-gray-200 sm:flex-row sm:flex-wrap sm:items-end">
                        <div className="min-w-[12rem] flex-1">
                            <label htmlFor="mr-search" className="block text-xs font-medium text-gray-500">
                                Search
                            </label>
                            <div className="relative mt-1">
                                <MagnifyingGlassIcon className="pointer-events-none absolute left-2 top-2.5 h-4 w-4 text-gray-400" />
                                <input
                                    id="mr-search"
                                    type="search"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Key or label…"
                                    className="w-full rounded-md border border-gray-300 py-2 pl-8 pr-3 text-sm"
                                />
                            </div>
                        </div>
                        <div>
                            <label htmlFor="mr-type" className="block text-xs font-medium text-gray-500">
                                Type
                            </label>
                            <select
                                id="mr-type"
                                value={filterType}
                                onChange={(e) => setFilterType(e.target.value)}
                                className="mt-1 block w-full rounded-md border border-gray-300 py-2 pl-3 pr-8 text-sm sm:w-40"
                            >
                                <option value="">All types</option>
                                {allTypes.map((t) => (
                                    <option key={t} value={t}>
                                        {t}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="mr-applies" className="block text-xs font-medium text-gray-500">
                                Applies to
                            </label>
                            <select
                                id="mr-applies"
                                value={filterApplies}
                                onChange={(e) => setFilterApplies(e.target.value)}
                                className="mt-1 block w-full rounded-md border border-gray-300 py-2 pl-3 pr-8 text-sm sm:w-36"
                            >
                                <option value="">All</option>
                                {['all', 'image', 'video', 'document'].map((v) => (
                                    <option key={v} value={v}>
                                        {v}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="mr-pop" className="block text-xs font-medium text-gray-500">
                                Population
                            </label>
                            <select
                                id="mr-pop"
                                value={filterPopulation}
                                onChange={(e) => setFilterPopulation(e.target.value)}
                                className="mt-1 block w-full rounded-md border border-gray-300 py-2 pl-3 pr-8 text-sm sm:w-36"
                            >
                                <option value="">All</option>
                                {['manual', 'automatic', 'hybrid'].map((v) => (
                                    <option key={v} value={v}>
                                        {v}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="min-w-[10rem] flex-1 sm:max-w-xs">
                            <label htmlFor="mr-tpl" className="block text-xs font-medium text-gray-500">
                                Default bundle includes
                            </label>
                            <select
                                id="mr-tpl"
                                value={filterTemplateId}
                                onChange={(e) => setFilterTemplateId(e.target.value)}
                                className="mt-1 block w-full rounded-md border border-gray-300 py-2 pl-3 pr-8 text-sm"
                            >
                                <option value="">Any template</option>
                                {templateChoices.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="overflow-x-auto">
                            {filteredFields.length === 0 ? (
                                <p className="px-6 py-10 text-center text-sm text-gray-500">No fields match filters.</p>
                            ) : (
                                groupedFiltered.map(([typeKey, typeFields]) => {
                                    const collapsed = !!collapsedTypes[typeKey]
                                    return (
                                        <div key={typeKey} className="border-b border-gray-200 last:border-b-0">
                                            <button
                                                type="button"
                                                onClick={() => toggleTypeCollapsed(typeKey)}
                                                className="flex w-full items-center gap-2 bg-gray-50 px-4 py-3 text-left text-sm font-semibold text-gray-800 hover:bg-gray-100"
                                            >
                                                {collapsed ? (
                                                    <ChevronRightIcon className="h-4 w-4 shrink-0 text-gray-500" />
                                                ) : (
                                                    <ChevronDownIcon className="h-4 w-4 shrink-0 text-gray-500" />
                                                )}
                                                <span>{typeKey}</span>
                                                <span className="font-normal text-gray-500">({typeFields.length})</span>
                                            </button>
                                            {!collapsed && (
                                                <table className="min-w-full divide-y divide-gray-200">
                                                    <thead className="bg-white">
                                                        <tr>
                                                            <th
                                                                scope="col"
                                                                className="py-3 pl-4 pr-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:pl-6"
                                                            >
                                                                Field
                                                            </th>
                                                            <th
                                                                scope="col"
                                                                className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                                                            >
                                                                Type
                                                            </th>
                                                            <th
                                                                scope="col"
                                                                className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                                                            >
                                                                Population
                                                            </th>
                                                            <th
                                                                scope="col"
                                                                className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                                                            >
                                                                Context
                                                            </th>
                                                            <th
                                                                scope="col"
                                                                className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                                                            >
                                                                Flags
                                                            </th>
                                                            <th
                                                                scope="col"
                                                                className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                                                            >
                                                                Usage
                                                            </th>
                                                            <th
                                                                scope="col"
                                                                className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                                                            >
                                                                Default templates
                                                            </th>
                                                            {canManageVisibility && (
                                                                <th
                                                                    scope="col"
                                                                    className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                                                                >
                                                                    Actions
                                                                </th>
                                                            )}
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y divide-gray-100 bg-white">
                                                        {typeFields.map((field) => renderFieldRow(field))}
                                                    </tbody>
                                                </table>
                                            )}
                                        </div>
                                    )
                                })
                            )}
                        </div>
                    </div>

                    {addModalOpen && (
                        <>
                            <div
                                className="fixed inset-0 z-50 bg-gray-500 bg-opacity-75 transition-opacity"
                                onClick={closeAddModal}
                                aria-hidden="true"
                            />
                            <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
                                <div
                                    className="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-lg bg-white p-6 shadow-xl"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    <div className="mb-4 flex items-start justify-between gap-4">
                                        <h2 className="text-lg font-semibold text-gray-900">Add system field</h2>
                                        <button
                                            type="button"
                                            onClick={closeAddModal}
                                            className="rounded-md text-gray-400 hover:text-gray-600"
                                        >
                                            <XMarkIcon className="h-6 w-6" />
                                        </button>
                                    </div>
                                    <form onSubmit={submitAddField} className="space-y-4">
                                        <div>
                                            <label htmlFor="af-key" className="block text-xs font-medium text-gray-700">
                                                Key (snake_case)
                                            </label>
                                            <input
                                                id="af-key"
                                                value={addFieldForm.key}
                                                onChange={(e) => setAddFieldForm((p) => ({ ...p, key: e.target.value }))}
                                                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm font-mono"
                                                required
                                                autoComplete="off"
                                            />
                                            {addFieldErrors.key && (
                                                <p className="mt-1 text-xs text-red-600">{addFieldErrors.key}</p>
                                            )}
                                        </div>
                                        <div>
                                            <label htmlFor="af-label" className="block text-xs font-medium text-gray-700">
                                                Label
                                            </label>
                                            <input
                                                id="af-label"
                                                value={addFieldForm.system_label}
                                                onChange={(e) =>
                                                    setAddFieldForm((p) => ({ ...p, system_label: e.target.value }))
                                                }
                                                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                                required
                                            />
                                            {addFieldErrors.system_label && (
                                                <p className="mt-1 text-xs text-red-600">{addFieldErrors.system_label}</p>
                                            )}
                                        </div>
                                        <div className="grid grid-cols-2 gap-3">
                                            <div>
                                                <label htmlFor="af-type" className="block text-xs font-medium text-gray-700">
                                                    Type
                                                </label>
                                                <select
                                                    id="af-type"
                                                    value={addFieldForm.type}
                                                    onChange={(e) => setAddFieldForm((p) => ({ ...p, type: e.target.value }))}
                                                    className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                                >
                                                    {['text', 'textarea', 'number', 'boolean', 'date', 'select', 'multiselect'].map(
                                                        (t) => (
                                                            <option key={t} value={t}>
                                                                {t}
                                                            </option>
                                                        )
                                                    )}
                                                </select>
                                            </div>
                                            <div>
                                                <label htmlFor="af-applies" className="block text-xs font-medium text-gray-700">
                                                    Applies to
                                                </label>
                                                <select
                                                    id="af-applies"
                                                    value={addFieldForm.applies_to}
                                                    onChange={(e) =>
                                                        setAddFieldForm((p) => ({ ...p, applies_to: e.target.value }))
                                                    }
                                                    className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                                >
                                                    {['all', 'image', 'video', 'document'].map((t) => (
                                                        <option key={t} value={t}>
                                                            {t}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                        </div>
                                        <div>
                                            <label htmlFor="af-pop" className="block text-xs font-medium text-gray-700">
                                                Population
                                            </label>
                                            <select
                                                id="af-pop"
                                                value={addFieldForm.population_mode}
                                                onChange={(e) =>
                                                    setAddFieldForm((p) => ({ ...p, population_mode: e.target.value }))
                                                }
                                                className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                            >
                                                {['manual', 'automatic', 'hybrid'].map((t) => (
                                                    <option key={t} value={t}>
                                                        {t}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        {(addFieldForm.type === 'select' || addFieldForm.type === 'multiselect') && (
                                            <div>
                                                <p className="text-xs font-medium text-gray-700">Options</p>
                                                {addOptions.map((row, i) => (
                                                    <div key={i} className="mt-2 flex gap-2">
                                                        <input
                                                            placeholder="value"
                                                            value={row.value}
                                                            onChange={(e) => {
                                                                const next = [...addOptions]
                                                                next[i] = { ...next[i], value: e.target.value }
                                                                setAddOptions(next)
                                                            }}
                                                            className="flex-1 rounded-md border border-gray-300 px-2 py-1.5 text-sm font-mono"
                                                        />
                                                        <input
                                                            placeholder="label"
                                                            value={row.label}
                                                            onChange={(e) => {
                                                                const next = [...addOptions]
                                                                next[i] = { ...next[i], label: e.target.value }
                                                                setAddOptions(next)
                                                            }}
                                                            className="flex-1 rounded-md border border-gray-300 px-2 py-1.5 text-sm"
                                                        />
                                                    </div>
                                                ))}
                                                <button
                                                    type="button"
                                                    onClick={() => setAddOptions((o) => [...o, { value: '', label: '' }])}
                                                    className="mt-2 text-xs font-medium text-indigo-600 hover:text-indigo-800"
                                                >
                                                    + Add option row
                                                </button>
                                                {addFieldErrors.options && (
                                                    <p className="mt-1 text-xs text-red-600">{addFieldErrors.options}</p>
                                                )}
                                            </div>
                                        )}
                                        <div className="flex flex-wrap gap-4 text-sm">
                                            <label className="inline-flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    checked={addFieldForm.show_on_upload}
                                                    onChange={(e) =>
                                                        setAddFieldForm((p) => ({ ...p, show_on_upload: e.target.checked }))
                                                    }
                                                    className="rounded border-gray-300"
                                                />
                                                Upload
                                            </label>
                                            <label className="inline-flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    checked={addFieldForm.show_on_edit}
                                                    onChange={(e) =>
                                                        setAddFieldForm((p) => ({ ...p, show_on_edit: e.target.checked }))
                                                    }
                                                    className="rounded border-gray-300"
                                                />
                                                Edit
                                            </label>
                                            <label className="inline-flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    checked={addFieldForm.show_in_filters}
                                                    onChange={(e) =>
                                                        setAddFieldForm((p) => ({ ...p, show_in_filters: e.target.checked }))
                                                    }
                                                    className="rounded border-gray-300"
                                                />
                                                Filters
                                            </label>
                                            <label className="inline-flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    checked={addFieldForm.readonly}
                                                    onChange={(e) =>
                                                        setAddFieldForm((p) => ({ ...p, readonly: e.target.checked }))
                                                    }
                                                    className="rounded border-gray-300"
                                                />
                                                Read-only
                                            </label>
                                        </div>
                                        {latestSystemTemplates.length > 0 && (
                                            <div>
                                                <p className="text-xs font-medium text-gray-700">
                                                    Add to template bundles (optional)
                                                </p>
                                                <p className="mt-0.5 text-xs text-gray-500">
                                                    Creates default bundle rows; all surfaces visible unless you edit the bundle later.
                                                </p>
                                                <div className="mt-2 max-h-40 space-y-1 overflow-y-auto rounded border border-gray-200 p-2">
                                                    {latestSystemTemplates.map((t) => (
                                                        <label
                                                            key={t.id}
                                                            className="flex cursor-pointer items-center gap-2 text-sm text-gray-800"
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                checked={selectedTemplateIds.has(t.id)}
                                                                onChange={() => toggleTemplateSelected(t.id)}
                                                                className="rounded border-gray-300"
                                                            />
                                                            <span className="truncate">
                                                                {t.name}{' '}
                                                                <span className="text-gray-400">({t.asset_type})</span>
                                                            </span>
                                                        </label>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                        <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                                            <button
                                                type="button"
                                                onClick={closeAddModal}
                                                className="rounded-md px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50"
                                            >
                                                Cancel
                                            </button>
                                            <button
                                                type="submit"
                                                disabled={addFieldProcessing}
                                                className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                                            >
                                                {addFieldProcessing ? 'Creating…' : 'Create field'}
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </>
                    )}

                    {categoryModalOpen && selectedField && (
                        <>
                            <div
                                className="fixed inset-0 z-50 bg-gray-500 bg-opacity-75 transition-opacity"
                                onClick={closeCategoryModal}
                                aria-hidden="true"
                            />
                            <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                                <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl">
                                    <div className="absolute right-0 top-0 hidden pr-4 pt-4 sm:block">
                                        <button
                                            type="button"
                                            className="rounded-md bg-white text-gray-400 hover:text-gray-500"
                                            onClick={closeCategoryModal}
                                        >
                                            <span className="sr-only">Close</span>
                                            <XMarkIcon className="h-6 w-6" />
                                        </button>
                                    </div>
                                    <div className="sm:flex sm:items-start">
                                        <div className="mt-3 w-full text-center sm:mt-0 sm:text-left">
                                            <h3 className="mb-2 text-base font-semibold leading-6 text-gray-900">
                                                Category Visibility: {selectedField.label}
                                            </h3>
                                            <p className="mb-4 text-sm text-gray-500">
                                                Configure which system categories this field is visible for. Suppressing a field hides it in upload, edit, and filter UIs for that category.
                                            </p>
                                            <div className="mb-4 rounded-md border border-yellow-200 bg-yellow-50 p-3">
                                                <div className="flex">
                                                    <InformationCircleIcon className="h-5 w-5 flex-shrink-0 text-yellow-600" />
                                                    <div className="ml-3">
                                                        <p className="text-sm text-yellow-800">
                                                            <strong>Note:</strong> Suppressing hides from UI; existing metadata values remain.
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="max-h-96 overflow-y-auto rounded-md border border-gray-200">
                                                {loadingCategories ? (
                                                    <div className="p-4 text-center text-sm text-gray-500">Loading…</div>
                                                ) : categories.length === 0 ? (
                                                    <div className="p-4 text-center text-sm text-gray-500">No categories found.</div>
                                                ) : (
                                                    <table className="min-w-full divide-y divide-gray-200">
                                                        <thead className="bg-gray-50">
                                                            <tr>
                                                                <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">
                                                                    Category
                                                                </th>
                                                                <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">
                                                                    Asset Type
                                                                </th>
                                                                <th className="px-4 py-3 text-center text-xs font-medium uppercase text-gray-500">
                                                                    Status
                                                                </th>
                                                                <th className="px-4 py-3 text-center text-xs font-medium uppercase text-gray-500">
                                                                    Action
                                                                </th>
                                                            </tr>
                                                        </thead>
                                                        <tbody className="divide-y divide-gray-200 bg-white">
                                                            {categories.map((category) => (
                                                                <tr key={category.id}>
                                                                    <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900">
                                                                        {category.name}
                                                                    </td>
                                                                    <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-500">
                                                                        {category.asset_type}
                                                                    </td>
                                                                    <td className="whitespace-nowrap px-4 py-3 text-center text-sm">
                                                                        {category.is_suppressed ? (
                                                                            <span className="inline-flex items-center rounded-md bg-red-100 px-2 py-1 text-xs font-medium text-red-800">
                                                                                Suppressed
                                                                            </span>
                                                                        ) : (
                                                                            <span className="inline-flex items-center rounded-md bg-green-100 px-2 py-1 text-xs font-medium text-green-800">
                                                                                Visible
                                                                            </span>
                                                                        )}
                                                                    </td>
                                                                    <td className="whitespace-nowrap px-4 py-3 text-center text-sm">
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => toggleCategorySuppression(category.id)}
                                                                            disabled={saving}
                                                                            className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${
                                                                                category.is_suppressed
                                                                                    ? 'bg-green-50 text-green-700 hover:bg-green-100'
                                                                                    : 'bg-red-50 text-red-700 hover:bg-red-100'
                                                                            } disabled:opacity-50`}
                                                                        >
                                                                            {category.is_suppressed ? 'Unsuppress' : 'Suppress'}
                                                                        </button>
                                                                    </td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                )}
                                            </div>
                                            <div className="mt-4 flex justify-end">
                                                <button
                                                    type="button"
                                                    onClick={closeCategoryModal}
                                                    className="inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                                >
                                                    Close
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
