import { useState, useEffect, useCallback } from 'react'
import { useForm, Link, router, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import CategoryIconSelector from '../../Components/CategoryIconSelector'
import { CategoryIcon } from '../../Helpers/categoryIcons'
import ConfirmDialog from '../../Components/ConfirmDialog'
import {
    PlusIcon,
    PencilIcon,
    TrashIcon,
    XMarkIcon,
    CheckIcon,
    InformationCircleIcon,
    Bars3Icon,
    ChevronDownIcon,
} from '@heroicons/react/24/outline'

function groupSystemFieldsByType(fieldList) {
    const byType = new Map()
    for (const f of fieldList) {
        const t = (f.type || 'other').toString()
        if (!byType.has(t)) byType.set(t, [])
        byType.get(t).push(f)
    }
    return [...byType.entries()].sort((a, b) => a[0].localeCompare(b[0]))
}

function isShownSomewhereInTemplate(f) {
    return (
        !f.is_hidden ||
        !f.is_upload_hidden ||
        !f.is_filter_hidden ||
        !f.is_edit_hidden
    )
}

function hasPrimaryPlacementOverride(f) {
    return f.is_primary !== null && f.is_primary !== undefined
}

/** Shown expanded: visible in at least one surface, or filter primary is not “Default (system)”. */
function isActiveTemplateField(f) {
    if (f.is_system_suppressed) return false
    return isShownSomewhereInTemplate(f) || hasPrimaryPlacementOverride(f)
}

function partitionFieldsForTemplateEditor(rows) {
    const suppressed = []
    const active = []
    const inactive = []
    for (const f of rows) {
        if (f.is_system_suppressed) suppressed.push(f)
        else if (isActiveTemplateField(f)) active.push(f)
        else inactive.push(f)
    }
    return { active, inactive, suppressed }
}

function FieldDefaultCard({ f, updateField }) {
    const suppressed = f.is_system_suppressed
    const primaryVal =
        f.is_primary === null || f.is_primary === undefined ? '' : f.is_primary ? 'yes' : 'no'
    return (
        <li
            className={`rounded-xl border px-4 py-3 shadow-sm ${
                suppressed ? 'border-amber-200/80 bg-amber-50/35' : 'border-gray-200 bg-white'
            }`}
        >
            <div className="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium text-gray-900">{f.system_label}</span>
                        <span className="rounded-md bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-600">
                            {f.type}
                        </span>
                    </div>
                    <div className="font-mono text-xs text-gray-500">{f.key}</div>
                    {suppressed ? (
                        <p className="mt-2 text-xs text-amber-900/90">
                            <span className="font-medium">Globally suppressed</span> in the metadata registry —
                            tenants never see this field on this template family, regardless of defaults below.
                        </p>
                    ) : null}
                </div>
            </div>
            {!suppressed ? (
                <div className="mt-4 border-t border-gray-100 pt-3">
                    <p className="text-[11px] font-medium uppercase tracking-wide text-gray-400">Show in</p>
                    <div className="mt-2 grid gap-2 sm:grid-cols-2">
                        <label className="flex cursor-pointer items-start gap-2 rounded-lg border border-transparent px-1 py-1 hover:border-gray-100 hover:bg-gray-50/80">
                            <input
                                type="checkbox"
                                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                checked={!f.is_hidden}
                                onChange={(e) =>
                                    updateField(f.metadata_field_id, { is_hidden: !e.target.checked })
                                }
                            />
                            <span>
                                <span className="block text-sm font-medium text-gray-800">Library grid</span>
                                <span className="block text-xs text-gray-500">Asset thumbnails and folder view</span>
                            </span>
                        </label>
                        <label className="flex cursor-pointer items-start gap-2 rounded-lg border border-transparent px-1 py-1 hover:border-gray-100 hover:bg-gray-50/80">
                            <input
                                type="checkbox"
                                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                checked={!f.is_upload_hidden}
                                onChange={(e) =>
                                    updateField(f.metadata_field_id, {
                                        is_upload_hidden: !e.target.checked,
                                    })
                                }
                            />
                            <span>
                                <span className="block text-sm font-medium text-gray-800">Upload flow</span>
                                <span className="block text-xs text-gray-500">When adding new assets</span>
                            </span>
                        </label>
                        <label className="flex cursor-pointer items-start gap-2 rounded-lg border border-transparent px-1 py-1 hover:border-gray-100 hover:bg-gray-50/80">
                            <input
                                type="checkbox"
                                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                checked={!f.is_filter_hidden}
                                onChange={(e) =>
                                    updateField(f.metadata_field_id, {
                                        is_filter_hidden: !e.target.checked,
                                    })
                                }
                            />
                            <span>
                                <span className="block text-sm font-medium text-gray-800">Filters</span>
                                <span className="block text-xs text-gray-500">Library filter sidebar</span>
                            </span>
                        </label>
                        <label className="flex cursor-pointer items-start gap-2 rounded-lg border border-transparent px-1 py-1 hover:border-gray-100 hover:bg-gray-50/80">
                            <input
                                type="checkbox"
                                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                checked={!f.is_edit_hidden}
                                onChange={(e) =>
                                    updateField(f.metadata_field_id, { is_edit_hidden: !e.target.checked })
                                }
                            />
                            <span>
                                <span className="block text-sm font-medium text-gray-800">Edit / detail</span>
                                <span className="block text-xs text-gray-500">Asset panel and bulk edit</span>
                            </span>
                        </label>
                    </div>
                    <div className="mt-4 flex flex-col gap-1.5 sm:flex-row sm:items-center sm:gap-3">
                        <label
                            htmlFor={`primary-${f.metadata_field_id}`}
                            className="text-xs font-medium text-gray-600 sm:w-40 sm:shrink-0"
                        >
                            Filter sidebar placement
                        </label>
                        <select
                            id={`primary-${f.metadata_field_id}`}
                            className="block w-full max-w-xs rounded-md border-0 py-1.5 pl-2 pr-8 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-primary"
                            value={primaryVal}
                            onChange={(e) => {
                                const v = e.target.value
                                updateField(f.metadata_field_id, {
                                    is_primary: v === '' ? null : v === 'yes',
                                })
                            }}
                            aria-label={`Filter sidebar placement for ${f.key}`}
                        >
                            <option value="">Default (system)</option>
                            <option value="yes">Primary column</option>
                            <option value="no">Not primary</option>
                        </select>
                    </div>
                </div>
            ) : null}
        </li>
    )
}

function SystemCategoryFieldDefaultsPanel({ templateId, templateLabel, onClose }) {
    const getCsrf = () => document.querySelector('meta[name="csrf-token"]')?.content || ''
    const [loading, setLoading] = useState(true)
    const [saving, setSaving] = useState(false)
    const [err, setErr] = useState(null)
    const [fields, setFields] = useState([])
    const [filter, setFilter] = useState('')

    useEffect(() => {
        if (!templateId) return undefined
        let cancelled = false
        setLoading(true)
        setErr(null)
        setFilter('')
        const loadUrl =
            typeof route === 'function'
                ? route('admin.system-categories.field-defaults', { systemCategory: templateId })
                : `/app/admin/system-categories/${templateId}/field-defaults`
        fetch(loadUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => {
                if (!r.ok) throw new Error('Failed to load')
                return r.json()
            })
            .then((data) => {
                if (!cancelled) setFields(data.fields || [])
            })
            .catch(() => {
                if (!cancelled) setErr('Could not load field defaults.')
            })
            .finally(() => {
                if (!cancelled) setLoading(false)
            })
        return () => {
            cancelled = true
        }
    }, [templateId])

    const updateField = useCallback((id, patch) => {
        setFields((prev) => prev.map((f) => (f.metadata_field_id === id ? { ...f, ...patch } : f)))
    }, [])

    const save = async () => {
        const saveUrl =
            typeof route === 'function'
                ? route('admin.system-categories.field-defaults.update', { systemCategory: templateId })
                : `/app/admin/system-categories/${templateId}/field-defaults`
        const defaults = fields
            .filter((f) => !f.is_system_suppressed)
            .map((f) => ({
                metadata_field_id: f.metadata_field_id,
                is_hidden: !!f.is_hidden,
                is_upload_hidden: !!f.is_upload_hidden,
                is_filter_hidden: !!f.is_filter_hidden,
                is_edit_hidden: !!f.is_edit_hidden,
                is_primary: f.is_primary === undefined ? null : f.is_primary,
            }))
        setSaving(true)
        setErr(null)
        try {
            const res = await fetch(saveUrl, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ defaults }),
            })
            if (!res.ok) {
                const j = await res.json().catch(() => ({}))
                throw new Error(j.message || j.error || 'Save failed')
            }
            onClose()
        } catch (e) {
            setErr(e.message || 'Save failed')
        } finally {
            setSaving(false)
        }
    }

    const fq = filter.trim().toLowerCase()
    const visibleFields = fields.filter(
        (f) =>
            !fq ||
            String(f.key || '')
                .toLowerCase()
                .includes(fq) ||
            String(f.system_label || '')
                .toLowerCase()
                .includes(fq)
    )

    if (!templateId) return null

    return (
        <div
            className="fixed inset-0 z-50 flex justify-end bg-black/40"
            role="dialog"
            aria-modal="true"
            aria-labelledby="sc-field-defaults-title"
        >
            <button
                type="button"
                className="absolute inset-0 cursor-default"
                aria-label="Close panel"
                onClick={onClose}
            />
            <div className="relative flex h-full w-full max-w-4xl flex-col bg-white shadow-xl border-l border-gray-200">
                <div className="flex items-start justify-between gap-3 border-b border-gray-200 px-5 py-4">
                    <div className="min-w-0">
                        <h2 id="sc-field-defaults-title" className="text-lg font-semibold text-gray-900">
                            Default fields for template
                        </h2>
                        <p className="mt-1 text-sm text-gray-600 truncate" title={templateLabel}>
                            {templateLabel || `Template #${templateId}`}
                        </p>
                        <p className="mt-2 text-xs text-gray-500 leading-relaxed">
                            These defaults apply when a brand adds this folder from the catalog or when a new brand is
                            provisioned. Globally suppressed fields stay hidden regardless of toggles here.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                        aria-label="Close"
                    >
                        <XMarkIcon className="h-5 w-5" />
                    </button>
                </div>

                <div className="flex-1 overflow-y-auto px-5 py-4">
                    {loading ? (
                        <p className="text-sm text-gray-500">Loading fields…</p>
                    ) : err && fields.length === 0 ? (
                        <p className="text-sm text-red-600">{err}</p>
                    ) : (
                        <>
                            <input
                                type="search"
                                value={filter}
                                onChange={(e) => setFilter(e.target.value)}
                                placeholder="Filter by field name or key…"
                                className="mb-3 block w-full rounded-md border-0 py-1.5 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-primary"
                            />
                            <div className="mb-4 space-y-2 rounded-lg bg-slate-50 px-3 py-2.5 text-xs leading-relaxed text-slate-600 ring-1 ring-slate-200/80">
                                <p>
                                    <span className="font-semibold text-slate-800">How to read this</span>
                                    <span className="text-slate-500"> — </span>
                                    Check each place the field should{' '}
                                    <strong className="text-slate-700">appear</strong> for new brand folders. Unchecked
                                    means hidden there. “Filter sidebar placement” overrides primary vs secondary when
                                    the field is shown in filters.
                                </p>
                                <p className="border-t border-slate-200/80 pt-2 text-slate-600">
                                    <span className="font-semibold text-slate-800">Where on/off defaults come from</span>
                                    <span className="text-slate-500"> — </span>
                                    Values you save are stored in{' '}
                                    <code className="rounded bg-slate-200/60 px-1 py-0.5 text-[10px]">
                                        system_category_field_defaults
                                    </code>
                                    . If a field has no row yet, the app falls back to{' '}
                                    <code className="rounded bg-slate-200/60 px-1 py-0.5 text-[10px]">
                                        config/metadata_category_defaults.php
                                    </code>
                                    . Global suppression in the System Metadata Registry always wins over both.
                                </p>
                            </div>
                            <div className="space-y-8">
                                {visibleFields.length === 0 && fields.length > 0 ? (
                                    <p className="text-sm text-gray-500">No fields match your filter.</p>
                                ) : null}
                                {groupSystemFieldsByType(visibleFields).map(([typeKey, rows]) => {
                                    const { active, inactive, suppressed } = partitionFieldsForTemplateEditor(rows)
                                    return (
                                        <section key={typeKey} aria-labelledby={`field-type-${typeKey}`}>
                                            <h3
                                                id={`field-type-${typeKey}`}
                                                className="sticky top-0 z-[1] -mx-1 mb-3 border-b border-gray-100 bg-white/95 px-1 pb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 backdrop-blur-sm"
                                            >
                                                {typeKey} fields
                                                <span className="ml-2 font-normal normal-case text-gray-400">
                                                    ({rows.length}
                                                    {active.length > 0 ? (
                                                        <span className="text-emerald-600/90">
                                                            {' '}
                                                            · {active.length} active
                                                        </span>
                                                    ) : null}
                                                    )
                                                </span>
                                            </h3>
                                            {active.length > 0 ? (
                                                <ul className="space-y-3">
                                                    {active.map((f) => (
                                                        <FieldDefaultCard
                                                            key={f.metadata_field_id}
                                                            f={f}
                                                            updateField={updateField}
                                                        />
                                                    ))}
                                                </ul>
                                            ) : null}
                                            {inactive.length > 0 ? (
                                                <details
                                                    key={`inactive-${typeKey}-${fq || '_'}`}
                                                    className="group mt-3 rounded-lg border border-gray-200 bg-gray-50/60 open:border-gray-300 open:bg-white"
                                                    defaultOpen={Boolean(fq.trim() && active.length === 0)}
                                                >
                                                    <summary className="flex cursor-pointer list-none items-center justify-between gap-2 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100/80 [&::-webkit-details-marker]:hidden">
                                                        <span>
                                                            Not shown anywhere on this template ({inactive.length})
                                                            <span className="mt-0.5 block text-xs font-normal text-gray-500">
                                                                Hidden in grid, upload, filters, and edit — expand to turn
                                                                on
                                                            </span>
                                                        </span>
                                                        <ChevronDownIcon className="h-5 w-5 shrink-0 text-gray-400 transition-transform group-open:rotate-180" />
                                                    </summary>
                                                    <div className="border-t border-gray-200 px-2 pb-3 pt-2">
                                                        <ul className="space-y-3">
                                                            {inactive.map((f) => (
                                                                <FieldDefaultCard
                                                                    key={f.metadata_field_id}
                                                                    f={f}
                                                                    updateField={updateField}
                                                                />
                                                            ))}
                                                        </ul>
                                                    </div>
                                                </details>
                                            ) : null}
                                            {suppressed.length > 0 ? (
                                                <details className="group mt-3 rounded-lg border border-amber-200/80 bg-amber-50/40 open:bg-amber-50/60">
                                                    <summary className="flex cursor-pointer list-none items-center justify-between gap-2 px-3 py-2.5 text-sm font-medium text-amber-950/90 hover:bg-amber-100/50 [&::-webkit-details-marker]:hidden">
                                                        <span>
                                                            Globally suppressed ({suppressed.length})
                                                            <span className="mt-0.5 block text-xs font-normal text-amber-900/75">
                                                                Managed in System Metadata Registry — not editable here
                                                            </span>
                                                        </span>
                                                        <ChevronDownIcon className="h-5 w-5 shrink-0 text-amber-800/60 transition-transform group-open:rotate-180" />
                                                    </summary>
                                                    <div className="border-t border-amber-200/60 px-2 pb-3 pt-2">
                                                        <ul className="space-y-3">
                                                            {suppressed.map((f) => (
                                                                <FieldDefaultCard
                                                                    key={f.metadata_field_id}
                                                                    f={f}
                                                                    updateField={updateField}
                                                                />
                                                            ))}
                                                        </ul>
                                                    </div>
                                                </details>
                                            ) : null}
                                        </section>
                                    )
                                })}
                            </div>
                        </>
                    )}
                    {err && fields.length > 0 ? (
                        <p className="mt-3 text-sm text-red-600" role="alert">
                            {err}
                        </p>
                    ) : null}
                </div>

                <div className="flex items-center justify-end gap-3 border-t border-gray-200 px-5 py-4">
                    <button
                        type="button"
                        onClick={onClose}
                        className="text-sm font-semibold text-gray-700 hover:text-gray-900"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={saving || loading}
                        onClick={() => save()}
                        className="inline-flex rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-dark disabled:opacity-50"
                    >
                        {saving ? 'Saving…' : 'Save defaults'}
                    </button>
                </div>
            </div>
        </div>
    )
}

export default function SystemCategories({ templates, asset_types, admin_metadata_registry_url }) {
    const { auth, flash } = usePage().props
    const [showForm, setShowForm] = useState(false)
    const [editingTemplate, setEditingTemplate] = useState(null)
    const [deletingTemplate, setDeletingTemplate] = useState(null)
    const [draggedTemplate, setDraggedTemplate] = useState(null)
    const [localTemplates, setLocalTemplates] = useState(templates || [])
    const [deleteConfirm, setDeleteConfirm] = useState({ open: false, template: null })
    const [fieldDefaultsTemplateId, setFieldDefaultsTemplateId] = useState(null)

    useEffect(() => {
        if (typeof window === 'undefined') return
        const params = new URLSearchParams(window.location.search)
        const raw = params.get('openBundle')
        if (!raw) return
        const id = parseInt(raw, 10)
        if (!Number.isFinite(id) || id <= 0) return
        setFieldDefaultsTemplateId(id)
        params.delete('openBundle')
        const qs = params.toString()
        window.history.replaceState({}, '', `${window.location.pathname}${qs ? `?${qs}` : ''}`)
    }, [])

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        slug: '',
        icon: 'folder',
        asset_type: 'asset',
        is_hidden: false,
        auto_provision: false,
        sort_order: 0,
        seed_bundle_preset: 'none',
        seed_field_types: [],
    })

    const SEEDABLE_FIELD_TYPES = ['boolean', 'text', 'textarea', 'number', 'date', 'select', 'multiselect']

    const toggleSeedFieldType = (t) => {
        const cur = data.seed_field_types || []
        if (cur.includes(t)) {
            setData(
                'seed_field_types',
                cur.filter((x) => x !== t)
            )
        } else {
            setData('seed_field_types', [...cur, t])
        }
    }

    const runSeedBundlePreset = async (templateId, templateName, preset, fieldTypes = null) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        const body = { preset }
        if (fieldTypes && fieldTypes.length > 0) body.field_types = fieldTypes
        const res = await fetch(`/app/admin/system-categories/${templateId}/seed-bundle-preset`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
        })
        const json = await res.json().catch(() => ({}))
        if (!res.ok) {
            window.alert(json.error || json.message || 'Failed to seed bundle')
            return
        }
        window.alert(`Seeded ${json.rows_upserted ?? 0} field default row(s) for ${templateName}.`)
    }

    const handleCreate = () => {
        setEditingTemplate(null)
        reset()
        setShowForm(true)
    }

    const handleEdit = (template) => {
        setEditingTemplate(template)
        setData({
            name: template.name,
            slug: template.slug,
            icon: template.icon || 'folder',
            asset_type: template.asset_type,
            is_hidden: template.is_hidden,
            auto_provision: !!template.auto_provision,
            sort_order: template.sort_order,
            seed_bundle_preset: 'none',
            seed_field_types: [],
        })
        setShowForm(true)
    }

    const handleCancel = () => {
        setShowForm(false)
        setEditingTemplate(null)
        reset()
    }

    const handleSubmit = (e) => {
        e.preventDefault()
        if (editingTemplate) {
            put(`/app/admin/system-categories/${editingTemplate.id}`, {
                onSuccess: () => {
                    handleCancel()
                },
            })
        } else {
            post('/app/admin/system-categories', {
                onSuccess: () => {
                    handleCancel()
                },
            })
        }
    }

    const handleDelete = (template) => {
        setDeleteConfirm({ open: true, template })
    }

    const confirmDelete = () => {
        if (deleteConfirm.template) {
            router.delete(`/app/admin/system-categories/${deleteConfirm.template.id}`, {
                preserveScroll: true,
                onSuccess: () => {
                    setDeleteConfirm({ open: false, template: null })
                    setDeletingTemplate(null)
                },
            })
        }
    }

    // Update local templates when props change
    useEffect(() => {
        setLocalTemplates(templates || [])
    }, [templates])

    const handleDragStart = (e, template) => {
        setDraggedTemplate(template)
        e.dataTransfer.effectAllowed = 'move'
        e.dataTransfer.setData('text/plain', template.id?.toString() || '')
        // Make the dragged element semi-transparent
        if (e.target) {
            e.target.style.opacity = '0.5'
        }
    }

    const handleDragOver = (e) => {
        e.preventDefault()
        e.stopPropagation()
        e.dataTransfer.dropEffect = 'move'
    }

    const handleDragEnter = (e) => {
        e.preventDefault()
        e.stopPropagation()
    }

    const handleDrop = (e, targetTemplate, assetType) => {
        e.preventDefault()
        e.stopPropagation()
        if (!draggedTemplate || draggedTemplate.id === targetTemplate.id || draggedTemplate.asset_type !== assetType) {
            setDraggedTemplate(null)
            return
        }

        const filteredTemplates = localTemplates.filter(t => t.asset_type === assetType)
        const draggedIndex = filteredTemplates.findIndex(t => t.id === draggedTemplate.id)
        const targetIndex = filteredTemplates.findIndex(t => t.id === targetTemplate.id)

        if (draggedIndex === -1 || targetIndex === -1) {
            setDraggedTemplate(null)
            return
        }

        // Create new order array
        const newTemplates = [...filteredTemplates]
        const [removed] = newTemplates.splice(draggedIndex, 1)
        newTemplates.splice(targetIndex, 0, removed)

        // Update sort_order values (use increments of 10 for easier reordering)
        const orderUpdates = newTemplates.map((template, index) => ({
            id: template.id,
            sort_order: index * 10,
        }))

        // Update local state immediately for better UX
        setLocalTemplates(prev => {
            const updated = [...prev]
            orderUpdates.forEach(({ id, sort_order }) => {
                const idx = updated.findIndex(t => t.id === id)
                if (idx !== -1) {
                    updated[idx] = { ...updated[idx], sort_order }
                }
            })
            return updated
        })

        // Send update to server
        router.post('/app/admin/system-categories/update-order', {
            templates: orderUpdates,
        }, {
            preserveScroll: true,
            onError: () => {
                // Revert on error
                setLocalTemplates(templates || [])
            },
        })

        setDraggedTemplate(null)
    }

    const handleDragEnd = (e) => {
        // Reset opacity
        if (e.target) {
            e.target.style.opacity = ''
        }
        setDraggedTemplate(null)
    }

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-8">
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2 mb-4">
                            <Link
                                href="/app/admin"
                                className="text-sm font-medium text-gray-500 hover:text-gray-700 inline-block"
                            >
                                ← Back to Admin
                            </Link>
                            {admin_metadata_registry_url ? (
                                <Link
                                    href={admin_metadata_registry_url}
                                    className="text-sm font-medium text-primary hover:text-primary-dark inline-block"
                                >
                                    All system fields (table) →
                                </Link>
                            ) : null}
                        </div>
                        <div className="flex items-start justify-between">
                            <div className="flex-1">
                                <h1 className="text-3xl font-bold tracking-tight text-gray-900">System Categories</h1>
                                <p className="mt-2 text-sm text-gray-700">
                                    This page is the <strong>platform catalog</strong>: every template here is <em>available</em> to all tenants. <strong>Auto-add to new brands</strong> creates the folder row on new brands and queues a hidden copy on existing brands so tenants can show it when ready. <strong>Catalog only</strong> means the row is created when a tenant adds or shows that folder (same API as “add from catalog”). Saving a template pushes <strong>name and icon</strong> to all brand rows that already use this slug; tenants cannot rename system folders locally.
                                </p>
                                <div className="mt-4 rounded-md bg-blue-50 p-4">
                                    <div className="flex">
                                        <div className="flex-shrink-0">
                                            <InformationCircleIcon className="h-5 w-5 text-blue-400" aria-hidden="true" />
                                        </div>
                                        <div className="ml-3">
                                            <p className="text-sm text-blue-700">
                                                <strong>Visibility:</strong> <strong>Hidden template default</strong> applies to <em>new</em> brands and to the template itself in listings. When auto-adding to existing brands, folders are created <strong>hidden</strong> regardless of that checkbox so libraries do not change until a tenant enables them in Metadata → By category. Each brand may show at most 20 visible categories per asset and executions library.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="ml-6 flex-shrink-0">
                                <button
                                    type="button"
                                    onClick={handleCreate}
                                    className="inline-flex items-center rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-dark focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                                >
                                    <PlusIcon className="-ml-0.5 mr-1.5 h-5 w-5" aria-hidden="true" />
                                    New Category
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Form Modal */}
                    {showForm && (
                        <div className="mb-6 rounded-lg bg-white shadow-sm ring-1 ring-gray-200 p-6">
                            <div className="flex items-center justify-between mb-4">
                                <h2 className="text-lg font-semibold text-gray-900">
                                    {editingTemplate ? 'Edit System Category' : 'Create System Category'}
                                </h2>
                                <button
                                    type="button"
                                    onClick={handleCancel}
                                    className="text-gray-400 hover:text-gray-500"
                                >
                                    <XMarkIcon className="h-5 w-5" aria-hidden="true" />
                                </button>
                            </div>

                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label htmlFor="name" className="block text-sm font-medium leading-6 text-gray-900">
                                            Name <span className="text-red-500">*</span>
                                        </label>
                                        <div className="mt-2">
                                            <input
                                                type="text"
                                                name="name"
                                                id="name"
                                                required
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                                className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-primary sm:text-sm sm:leading-6"
                                                placeholder="e.g., Logos"
                                            />
                                            {errors.name && <p className="mt-1 text-sm text-red-600">{errors.name}</p>}
                                        </div>
                                    </div>

                                    <div>
                                        <label htmlFor="slug" className="block text-sm font-medium leading-6 text-gray-900">
                                            Slug
                                        </label>
                                        <div className="mt-2">
                                            <input
                                                type="text"
                                                name="slug"
                                                id="slug"
                                                value={data.slug}
                                                onChange={(e) => setData('slug', e.target.value)}
                                                className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-primary sm:text-sm sm:leading-6"
                                                placeholder="Auto-generated from name"
                                            />
                                            <p className="mt-1 text-xs text-gray-500">Leave empty to auto-generate from name</p>
                                            {errors.slug && <p className="mt-1 text-sm text-red-600">{errors.slug}</p>}
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-sm font-medium leading-6 text-gray-900 mb-2">
                                        Icon
                                    </label>
                                    <CategoryIconSelector
                                        value={data.icon}
                                        onChange={(iconId) => setData('icon', iconId)}
                                    />
                                </div>

                                <div>
                                    <label htmlFor="asset_type" className="block text-sm font-medium leading-6 text-gray-900">
                                        Asset Type <span className="text-red-500">*</span>
                                    </label>
                                    <div className="mt-2">
                                        <select
                                            name="asset_type"
                                            id="asset_type"
                                            required
                                            value={data.asset_type}
                                            onChange={(e) => setData('asset_type', e.target.value)}
                                            className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-primary sm:text-sm sm:leading-6"
                                        >
                                            {asset_types.map((type) => (
                                                <option key={type.value} value={type.value}>
                                                    {type.label}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.asset_type && <p className="mt-1 text-sm text-red-600">{errors.asset_type}</p>}
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label htmlFor="sort_order" className="block text-sm font-medium leading-6 text-gray-900">
                                            Sort Order
                                        </label>
                                        <div className="mt-2">
                                            <input
                                                type="number"
                                                name="sort_order"
                                                id="sort_order"
                                                min="0"
                                                value={data.sort_order}
                                                onChange={(e) => setData('sort_order', parseInt(e.target.value) || 0)}
                                                className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-primary sm:text-sm sm:leading-6"
                                            />
                                            {errors.sort_order && <p className="mt-1 text-sm text-red-600">{errors.sort_order}</p>}
                                        </div>
                                    </div>

                                    <div className="flex flex-col gap-4 sm:flex-row sm:gap-8">
                                        <div className="flex flex-col gap-1 max-w-md">
                                            <div className="flex items-start gap-3">
                                                <input
                                                    id="is_hidden"
                                                    name="is_hidden"
                                                    type="checkbox"
                                                    checked={data.is_hidden}
                                                    onChange={(e) => setData('is_hidden', e.target.checked)}
                                                    className="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                                />
                                                <label htmlFor="is_hidden" className="block text-sm font-medium text-gray-900">
                                                    Hidden by default (new brands)
                                                </label>
                                            </div>
                                            <p className="text-xs text-gray-500 pl-7">
                                                Applies when a <strong>new</strong> brand is created. Existing brands always get new auto-added folders <strong>hidden</strong> until a tenant shows them.
                                            </p>
                                        </div>
                                        <div className="flex flex-col gap-1 max-w-md">
                                            <div className="flex items-start gap-3">
                                                <input
                                                    id="auto_provision"
                                                    name="auto_provision"
                                                    type="checkbox"
                                                    checked={data.auto_provision}
                                                    onChange={(e) => setData('auto_provision', e.target.checked)}
                                                    className="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                                />
                                                <label htmlFor="auto_provision" className="block text-sm font-medium text-gray-900">
                                                    Auto-add to brands (new + existing)
                                                </label>
                                            </div>
                                            <p className="text-xs text-gray-500 pl-7">
                                                Queues a background job to create the folder on every brand that does not have it yet (hidden on existing brands).
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                {!editingTemplate && (
                                    <div className="rounded-md border border-gray-200 bg-gray-50/80 p-4">
                                        <label htmlFor="seed_bundle_preset" className="block text-sm font-medium text-gray-900">
                                            Optional: seed field bundle after create
                                        </label>
                                        <p className="mt-1 text-xs text-gray-600">
                                            Writes <code className="rounded bg-white px-1">system_category_field_defaults</code>{' '}
                                            from a preset. You can still edit the bundle afterward.
                                        </p>
                                        <select
                                            id="seed_bundle_preset"
                                            value={data.seed_bundle_preset}
                                            onChange={(e) => setData('seed_bundle_preset', e.target.value)}
                                            className="mt-2 block w-full max-w-md rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 sm:text-sm"
                                        >
                                            <option value="none">None (config fallback until you edit bundle)</option>
                                            <option value="minimal">Minimal — tags, collection, starred</option>
                                            <option value="photography_like">Rich — common field types (boolean, selects, text, …)</option>
                                            <option value="by_field_types">By field types (choose below)</option>
                                        </select>
                                        {data.seed_bundle_preset === 'by_field_types' && (
                                            <div className="mt-3 flex flex-wrap gap-2">
                                                {SEEDABLE_FIELD_TYPES.map((t) => (
                                                    <label
                                                        key={t}
                                                        className="inline-flex cursor-pointer items-center gap-1 rounded-md bg-white px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-200"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={(data.seed_field_types || []).includes(t)}
                                                            onChange={() => toggleSeedFieldType(t)}
                                                            className="rounded border-gray-300 text-primary focus:ring-primary"
                                                        />
                                                        {t}
                                                    </label>
                                                ))}
                                            </div>
                                        )}
                                        {errors.seed_bundle_preset && (
                                            <p className="mt-1 text-sm text-red-600">{errors.seed_bundle_preset}</p>
                                        )}
                                    </div>
                                )}

                                {errors.error && (
                                    <div className="rounded-md bg-red-50 p-4">
                                        <p className="text-sm text-red-800">{errors.error}</p>
                                    </div>
                                )}

                                <div className="flex items-center justify-end gap-3 pt-4">
                                    <button
                                        type="button"
                                        onClick={handleCancel}
                                        className="text-sm font-semibold leading-6 text-gray-900"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="inline-flex justify-center rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-dark focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50"
                                    >
                                        {processing ? 'Saving...' : editingTemplate ? 'Update' : 'Create'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    )}

                    {/* Categories Tables - Separated by Asset Type */}
                    <div className="space-y-6">
                        {/* Asset Categories */}
                        <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="px-6 py-4 border-b border-gray-200">
                                <h2 className="text-lg font-semibold text-gray-900">Asset Categories</h2>
                                <p className="mt-1 text-sm text-gray-500">
                                    Categories for assets (logos, graphics, photography, etc.)
                                </p>
                            </div>

                            {localTemplates.filter(t => t.asset_type === 'asset').length === 0 ? (
                                <div className="px-6 py-12 text-center">
                                    <p className="text-sm text-gray-500">No asset categories yet.</p>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setData('asset_type', 'asset')
                                            handleCreate()
                                        }}
                                        className="mt-4 inline-flex items-center rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-dark"
                                    >
                                        <PlusIcon className="-ml-0.5 mr-1.5 h-5 w-5" aria-hidden="true" />
                                        Create Asset Category
                                    </button>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-10">
                                                    <span className="sr-only">Drag handle</span>
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Name
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Slug
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Sort Order
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Flags
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    New brands
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Brand folders
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Field defaults
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {localTemplates.filter(t => t.asset_type === 'asset').map((template) => (
                                                <tr 
                                                    key={template.id} 
                                                    className="hover:bg-gray-50"
                                                    draggable
                                                    onDragStart={(e) => handleDragStart(e, template)}
                                                    onDragOver={handleDragOver}
                                                    onDragEnter={handleDragEnter}
                                                    onDrop={(e) => handleDrop(e, template, 'asset')}
                                                    onDragEnd={handleDragEnd}
                                                >
                                                    <td className="px-6 py-4 whitespace-nowrap cursor-move">
                                                        <Bars3Icon className="h-5 w-5 text-gray-400" aria-hidden="true" />
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="flex items-center gap-3">
                                                            <CategoryIcon 
                                                                iconId={template.icon || 'folder'} 
                                                                className="h-5 w-5 flex-shrink-0" 
                                                                color="text-gray-400"
                                                            />
                                                            <div className="text-sm font-medium text-gray-900">{template.name}</div>
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-500">{template.slug}</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-500">{template.sort_order}</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="flex gap-2">
                                                            {template.is_hidden && (
                                                                <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-gray-50 text-gray-700 ring-gray-600/20">
                                                                    Hidden
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        {template.auto_provision ? (
                                                            <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-green-50 text-green-800 ring-green-600/20">
                                                                Auto-add
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-gray-50 text-gray-600 ring-gray-600/20">
                                                                Catalog only
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-700 tabular-nums">
                                                            {typeof template.brand_row_count === 'number' ? template.brand_row_count : '—'}
                                                        </div>
                                                        <div className="text-xs text-gray-500">rows across brands</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                        {template.is_latest_version ? (
                                                            <div className="flex flex-col gap-2">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setFieldDefaultsTemplateId(template.id)}
                                                                    className="text-left font-medium text-primary hover:text-primary-dark"
                                                                >
                                                                    Edit bundle
                                                                </button>
                                                                <select
                                                                    aria-label={`Seed bundle preset for ${template.name}`}
                                                                    className="max-w-[11rem] rounded-md border-gray-300 py-1 text-xs text-gray-800 shadow-sm"
                                                                    defaultValue=""
                                                                    onChange={async (e) => {
                                                                        const preset = e.target.value
                                                                        e.target.value = ''
                                                                        if (!preset) return
                                                                        if (
                                                                            !window.confirm(
                                                                                `Seed "${preset}" defaults into ${template.name}? Existing bundle rows for matched fields will be updated.`
                                                                            )
                                                                        ) {
                                                                            return
                                                                        }
                                                                        await runSeedBundlePreset(template.id, template.name, preset)
                                                                    }}
                                                                >
                                                                    <option value="">Seed preset…</option>
                                                                    <option value="minimal">Minimal</option>
                                                                    <option value="photography_like">Rich types</option>
                                                                </select>
                                                            </div>
                                                        ) : (
                                                            <span className="text-gray-400 text-xs">Latest version only</span>
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                        <div className="flex items-center justify-end gap-2">
                                                            <button
                                                                type="button"
                                                                onClick={() => handleEdit(template)}
                                                                className="text-primary hover:text-primary-dark"
                                                            >
                                                                <PencilIcon className="h-5 w-5" aria-hidden="true" />
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={() => handleDelete(template)}
                                                                className="text-red-600 hover:text-red-700"
                                                            >
                                                                <TrashIcon className="h-5 w-5" aria-hidden="true" />
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>

                        {/* Deliverable Categories (UI label: Executions) */}
                        <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="px-6 py-4 border-b border-gray-200">
                                <h2 className="text-lg font-semibold text-gray-900">Execution Categories</h2>
                                <p className="mt-1 text-sm text-gray-500">
                                    Categories for executions (catalogs, press releases, digital ads, etc.)
                                </p>
                            </div>

                            {localTemplates.filter(t => t.asset_type === 'deliverable').length === 0 ? (
                                <div className="px-6 py-12 text-center">
                                    <p className="text-sm text-gray-500">No execution categories yet.</p>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setData('asset_type', 'deliverable')
                                            handleCreate()
                                        }}
                                        className="mt-4 inline-flex items-center rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-dark"
                                    >
                                        <PlusIcon className="-ml-0.5 mr-1.5 h-5 w-5" aria-hidden="true" />
                                        Create Execution Category
                                    </button>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-10">
                                                    <span className="sr-only">Drag handle</span>
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Name
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Slug
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Sort Order
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Flags
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    New brands
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Brand folders
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Field defaults
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {localTemplates.filter(t => t.asset_type === 'deliverable').map((template) => (
                                                <tr 
                                                    key={template.id} 
                                                    className="hover:bg-gray-50"
                                                    draggable
                                                    onDragStart={(e) => handleDragStart(e, template)}
                                                    onDragOver={handleDragOver}
                                                    onDragEnter={handleDragEnter}
                                                    onDrop={(e) => handleDrop(e, template, 'deliverable')}
                                                    onDragEnd={handleDragEnd}
                                                >
                                                    <td className="px-6 py-4 whitespace-nowrap cursor-move">
                                                        <Bars3Icon className="h-5 w-5 text-gray-400" aria-hidden="true" />
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="flex items-center gap-3">
                                                            <CategoryIcon 
                                                                iconId={template.icon || 'folder'} 
                                                                className="h-5 w-5 flex-shrink-0" 
                                                                color="text-gray-400"
                                                            />
                                                            <div className="text-sm font-medium text-gray-900">{template.name}</div>
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-500">{template.slug}</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-500">{template.sort_order}</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="flex gap-2">
                                                            {template.is_hidden && (
                                                                <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-gray-50 text-gray-700 ring-gray-600/20">
                                                                    Hidden
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        {template.auto_provision ? (
                                                            <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-green-50 text-green-800 ring-green-600/20">
                                                                Auto-add
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-gray-50 text-gray-600 ring-gray-600/20">
                                                                Catalog only
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-700 tabular-nums">
                                                            {typeof template.brand_row_count === 'number' ? template.brand_row_count : '—'}
                                                        </div>
                                                        <div className="text-xs text-gray-500">rows across brands</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                        {template.is_latest_version ? (
                                                            <div className="flex flex-col gap-2">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setFieldDefaultsTemplateId(template.id)}
                                                                    className="text-left font-medium text-primary hover:text-primary-dark"
                                                                >
                                                                    Edit bundle
                                                                </button>
                                                                <select
                                                                    aria-label={`Seed bundle preset for ${template.name}`}
                                                                    className="max-w-[11rem] rounded-md border-gray-300 py-1 text-xs text-gray-800 shadow-sm"
                                                                    defaultValue=""
                                                                    onChange={async (e) => {
                                                                        const preset = e.target.value
                                                                        e.target.value = ''
                                                                        if (!preset) return
                                                                        if (
                                                                            !window.confirm(
                                                                                `Seed "${preset}" defaults into ${template.name}? Existing bundle rows for matched fields will be updated.`
                                                                            )
                                                                        ) {
                                                                            return
                                                                        }
                                                                        await runSeedBundlePreset(template.id, template.name, preset)
                                                                    }}
                                                                >
                                                                    <option value="">Seed preset…</option>
                                                                    <option value="minimal">Minimal</option>
                                                                    <option value="photography_like">Rich types</option>
                                                                </select>
                                                            </div>
                                                        ) : (
                                                            <span className="text-gray-400 text-xs">Latest version only</span>
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                        <div className="flex items-center justify-end gap-2">
                                                            <button
                                                                type="button"
                                                                onClick={() => handleEdit(template)}
                                                                className="text-primary hover:text-primary-dark"
                                                            >
                                                                <PencilIcon className="h-5 w-5" aria-hidden="true" />
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={() => handleDelete(template)}
                                                                className="text-red-600 hover:text-red-700"
                                                            >
                                                                <TrashIcon className="h-5 w-5" aria-hidden="true" />
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />

            {fieldDefaultsTemplateId != null && (
                <SystemCategoryFieldDefaultsPanel
                    templateId={fieldDefaultsTemplateId}
                    templateLabel={localTemplates.find((t) => t.id === fieldDefaultsTemplateId)?.name || ''}
                    onClose={() => setFieldDefaultsTemplateId(null)}
                />
            )}
            
            {/* Delete Confirmation Dialog */}
            <ConfirmDialog
                open={deleteConfirm.open}
                onClose={() => setDeleteConfirm({ open: false, template: null })}
                onConfirm={confirmDelete}
                title="Delete System Category"
                message={
                    deleteConfirm.template
                        ? `Are you sure you want to delete the system category "${deleteConfirm.template.name}"? This will mark the template for deletion and allow tenants to delete their existing categories based on this template. This action cannot be undone.`
                        : ''
                }
                confirmText="Delete"
                cancelText="Cancel"
                variant="danger"
                loading={processing}
            />
        </div>
    )
}
