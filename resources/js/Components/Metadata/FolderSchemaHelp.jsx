import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, usePage } from '@inertiajs/react'
import { Popover, PopoverButton, PopoverPanel, Switch } from '@headlessui/react'
import {
    ChevronRightIcon,
    Cog6ToothIcon,
    InformationCircleIcon,
    PlusIcon,
} from '@heroicons/react/24/outline'
import { useBrandWorkbenchChrome } from '../../contexts/BrandWorkbenchChromeContext'
import { usePermission } from '../../hooks/usePermission'
import { buildBrandWorkbenchChromePackage } from '../../utils/brandWorkbenchTheme'

const MANAGE_CATEGORIES_URL =
    typeof route === 'function' ? route('manage.categories') : '/app/manage/categories'

function manageCategoriesHref(slug) {
    if (!slug) return MANAGE_CATEGORIES_URL
    return `${MANAGE_CATEGORIES_URL}?${new URLSearchParams({ category: slug })}`
}

/** Manage → Categories with folder selected and field editor opened to the values section. */
function manageCategoriesFieldValuesHref(slug, fieldId) {
    const params = new URLSearchParams()
    if (slug) params.set('category', slug)
    if (fieldId != null && fieldId !== '') {
        params.set('field_values', String(fieldId))
    }
    const qs = params.toString()
    return qs ? `${MANAGE_CATEGORIES_URL}?${qs}` : MANAGE_CATEGORIES_URL
}

/** Opens Manage hub with folder selected and “new field” section revealed. */
function manageCategoriesNewFieldHref(slug) {
    const params = new URLSearchParams()
    if (slug) params.set('category', slug)
    params.set('new_field', '1')
    return `${MANAGE_CATEGORIES_URL}?${params.toString()}`
}

function tenantFieldHref(fieldId) {
    if (typeof route === 'function') {
        return route('tenant.metadata.fields.show', fieldId)
    }
    return `/app/tenant/metadata/fields/${fieldId}`
}

function getCsrfToken() {
    if (typeof document === 'undefined') return ''
    return document.querySelector('meta[name="csrf-token"]')?.content || ''
}

function shortTypeLabel(field) {
    const t = field.field_type
    if (t === 'multiselect') return 'Multiselect'
    if (t === 'select') return 'Select'
    if (t === 'date' || t === 'datetime') return 'Date'
    if (t === 'number' || t === 'integer' || t === 'float') return 'Number'
    if (t === 'boolean' || t === 'checkbox') return 'Yes / No'
    return t ? String(t) : 'Text'
}

function isBroadOrganizingField(field) {
    const key = String(field.key || '').toLowerCase()
    const label = String(field.label || '').toLowerCase()
    const hay = `${key} ${label}`
    if (/\btags?\b/.test(hay)) return true
    if (/\bcollection\b/.test(hay) || key.includes('collection')) return true
    if (/\bkeyword\b/.test(hay)) return true
    if (key === 'tags' || key.endsWith('_tags')) return true
    return false
}

/** Built-in “Type” (asset kind) — ordered after custom fields, before other system fields. */
function isTypeField(field) {
    const key = String(field.key || '').toLowerCase()
    const label = String(field.label || '').trim().toLowerCase()
    return key === 'type' || label === 'type'
}

/** Right-pane title: "Graphics type" instead of bare "Type" when folder + type field. */
function folderScopedTypeDisplayTitle(folderLabel, field) {
    if (!folderLabel?.trim() || !isTypeField(field)) {
        return field.label
    }
    const fl = folderLabel.trim()
    const raw = String(field.label || 'Type').trim()
    const tail = raw.length ? raw.charAt(0).toLowerCase() + raw.slice(1) : 'type'
    return `${fl} ${tail}`
}

/**
 * Custom fields first, then Type, then other built-ins, tags/collections last.
 * (System automated fields are excluded before sorting.)
 */
function sortFieldsForDisplay(fields) {
    const tier = (f) => {
        if (!f.is_system) return 0
        if (isTypeField(f)) return 1
        if (isBroadOrganizingField(f)) return 3
        return 2
    }
    return [...fields].sort((a, b) => {
        const ta = tier(a)
        const tb = tier(b)
        if (ta !== tb) return ta - tb
        return String(a.label || '').localeCompare(String(b.label || ''), undefined, { sensitivity: 'base' })
    })
}

function FieldToggle({ field, categoryId, canToggle, onChanged, compact = false }) {
    const [busy, setBusy] = useState(false)
    const handleChange = async (checked) => {
        if (!canToggle || busy) return
        setBusy(true)
        try {
            const res = await fetch(
                `/app/api/tenant/metadata/fields/${field.id}/categories/${categoryId}/visibility`,
                {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ is_hidden: !checked }),
                }
            )
            if (res.ok) onChanged?.()
        } finally {
            setBusy(false)
        }
    }
    if (!canToggle) return null
    return (
        <div
            className={
                compact
                    ? 'flex shrink-0 items-center gap-2'
                    : 'flex w-full items-center justify-between gap-2 rounded-lg border border-slate-200/50 bg-slate-50/80 px-2 py-1.5'
            }
        >
            <span className={compact ? 'sr-only' : 'text-[11px] text-slate-600'}>On for this folder</span>
            <Switch
                checked={field.enabled_for_folder}
                disabled={busy}
                onChange={handleChange}
                className={`relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--wb-ring,#6366f180)] focus-visible:ring-offset-1 disabled:opacity-50 ${
                    field.enabled_for_folder
                        ? 'border-2 shadow-sm'
                        : 'border-2 border-slate-500 bg-slate-500 shadow-inner'
                }`}
                style={
                    field.enabled_for_folder
                        ? {
                              backgroundColor: 'var(--wb-accent, #6366f1)',
                              borderColor: 'color-mix(in srgb, var(--wb-accent, #6366f1) 78%, #0f172a)',
                          }
                        : undefined
                }
            >
                <span className="sr-only">Use {field.label} on this folder</span>
                <span
                    aria-hidden
                    className={`pointer-events-none inline-block h-4 w-4 translate-x-0 transform rounded-full transition ${
                        field.enabled_for_folder
                            ? 'translate-x-4 bg-white shadow-md ring-1 ring-black/20'
                            : 'translate-x-0.5 bg-white shadow-md ring-1 ring-slate-600/35'
                    }`}
                />
            </Switch>
        </div>
    )
}

function FieldDetailHeader({ field, folderLabel, categoryId, canToggle, onInvalidate }) {
    const displayTitle = folderScopedTypeDisplayTitle(folderLabel, field)
    return (
        <div className="border-b border-slate-100 bg-slate-50/70 px-3 py-2.5">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold text-slate-900" title={displayTitle}>
                        {displayTitle}
                    </p>
                    <p className="mt-0.5 text-[11px] text-slate-500">
                        {shortTypeLabel(field)}
                        {field.is_automated ? ' · Auto' : ''}
                        {!field.is_system ? (
                            <span className="font-medium text-[var(--wb-accent)]"> · Custom</span>
                        ) : (
                            <span> · Built-in</span>
                        )}
                    </p>
                </div>
                <FieldToggle
                    field={field}
                    categoryId={categoryId}
                    canToggle={canToggle}
                    onChanged={onInvalidate}
                    compact
                />
            </div>
        </div>
    )
}

/**
 * Phase 2 — Folder Quick Filter controls inside the per-field submenu.
 *
 * Renders:
 *   - "Show in folder quick filters" toggle (eligibility-gated; disabled with
 *     a tooltip explanation when the field is ineligible)
 *   - When enabled: a compact order input
 *   - "Advanced" disclosure with a weight input (Phase 3 will read weight)
 *
 * Only patches the dedicated quick-filter route — never mutates the existing
 * folder-enable / visibility flags. When the backend `quick_filter` payload
 * is missing (older deploy / feature off), the whole control hides itself.
 */
function QuickFilterControls({ field, categoryId, canToggle, onChanged }) {
    const qf = field?.quick_filter
    const [busy, setBusy] = useState(false)
    const [showAdvanced, setShowAdvanced] = useState(false)
    if (!qf || qf.feature_enabled === false) return null

    const enabled = !!qf.enabled
    const supported = !!qf.supported
    const ineligibleReason = qf.ineligible_reason || null
    const order = qf.order
    const weight = qf.weight

    const patch = async (body) => {
        if (!canToggle || busy) return
        setBusy(true)
        try {
            const res = await fetch(
                `/app/api/tenant/metadata/fields/${field.id}/categories/${categoryId}/folder-quick-filter`,
                {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(body),
                }
            )
            if (res.ok) onChanged?.()
        } finally {
            setBusy(false)
        }
    }

    const onToggle = (next) => patch({ enabled: !!next })
    const onOrderChange = (e) => {
        const raw = e.target.value
        const parsed = raw === '' ? null : Number(raw)
        if (parsed !== null && (!Number.isInteger(parsed) || parsed < 0)) return
        patch({ order: parsed })
    }
    const onWeightChange = (e) => {
        const raw = e.target.value
        const parsed = raw === '' ? null : Number(raw)
        if (parsed !== null && (!Number.isInteger(parsed) || parsed < 0)) return
        patch({ weight: parsed })
    }

    const toggleClasses = `relative inline-flex h-5 w-9 shrink-0 rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--wb-ring,#6366f180)] focus-visible:ring-offset-1 ${
        supported && enabled
            ? 'border-2 shadow-sm'
            : 'border-2 border-slate-300 bg-slate-300 shadow-inner'
    } ${supported && canToggle ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'}`

    const knobClasses = `pointer-events-none inline-block h-4 w-4 transform rounded-full transition ${
        supported && enabled
            ? 'translate-x-4 bg-white shadow-md ring-1 ring-black/20'
            : 'translate-x-0.5 bg-white shadow-md ring-1 ring-slate-600/35'
    }`

    return (
        <div className="border-b border-slate-100 bg-white/60 px-3 py-2.5">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="text-[11px] font-medium text-slate-700">
                        Show in folder quick filters
                    </p>
                    <p className="mt-0.5 text-[10px] leading-snug text-slate-500">
                        {ineligibleReason
                            ? ineligibleReason
                            : 'Surfaces this filter as a contextual shortcut for this folder.'}
                    </p>
                </div>
                <Switch
                    checked={enabled}
                    disabled={!supported || !canToggle || busy}
                    onChange={onToggle}
                    className={toggleClasses}
                    style={
                        supported && enabled
                            ? {
                                  backgroundColor: 'var(--wb-accent, #6366f1)',
                                  borderColor:
                                      'color-mix(in srgb, var(--wb-accent, #6366f1) 78%, #0f172a)',
                              }
                            : undefined
                    }
                    title={ineligibleReason || undefined}
                >
                    <span className="sr-only">Show {field.label} as a folder quick filter</span>
                    <span aria-hidden className={knobClasses} />
                </Switch>
            </div>

            {supported && enabled ? (
                <div className="mt-2 flex items-center gap-2">
                    <label className="text-[10px] font-medium uppercase tracking-wide text-slate-500">
                        Order
                    </label>
                    <input
                        type="number"
                        min={0}
                        step={1}
                        defaultValue={order ?? ''}
                        onBlur={onOrderChange}
                        disabled={!canToggle || busy}
                        className="h-7 w-16 rounded-md border border-slate-200 bg-white px-2 text-[11px] text-slate-800 focus:border-[var(--wb-accent)] focus:outline-none focus:ring-1 focus:ring-[var(--wb-accent)] disabled:opacity-60"
                        aria-label="Quick filter order"
                    />
                    <button
                        type="button"
                        onClick={() => setShowAdvanced((v) => !v)}
                        className="ml-auto text-[10px] font-medium text-[var(--wb-link)] hover:opacity-90"
                    >
                        {showAdvanced ? 'Hide advanced' : 'Advanced'}
                    </button>
                </div>
            ) : null}

            {supported && enabled && showAdvanced ? (
                <div className="mt-2 flex items-center gap-2">
                    <label className="text-[10px] font-medium uppercase tracking-wide text-slate-500">
                        Weight
                    </label>
                    <input
                        type="number"
                        min={0}
                        step={1}
                        defaultValue={weight ?? ''}
                        onBlur={onWeightChange}
                        disabled={!canToggle || busy}
                        placeholder="—"
                        className="h-7 w-20 rounded-md border border-slate-200 bg-white px-2 text-[11px] text-slate-800 focus:border-[var(--wb-accent)] focus:outline-none focus:ring-1 focus:ring-[var(--wb-accent)] disabled:opacity-60"
                        aria-label="Quick filter weight"
                    />
                    <span className="text-[10px] italic text-slate-400">
                        Higher = more important. Reserved for future ranking.
                    </span>
                </div>
            ) : null}
        </div>
    )
}

function SubmenuPanel({ field, categoryId, categorySlug, folderLabel, permissions, onInvalidate }) {
    const hasList = field.values_expandable && field.options_total > 0
    const isListType = field.field_type === 'select' || field.field_type === 'multiselect'
    const preview = field.options_preview || []
    const rest = Math.max(0, field.options_total - preview.length)
    const canToggle = permissions?.can_toggle_folder_field
    const canEditDef = permissions?.can_edit_definitions
    const canValues = permissions?.can_manage_option_values

    const compactNoOptions = isListType && !hasList
    const showAddValueCta = canValues && isListType && !field.option_editing_restricted
    const showManageButton = canEditDef || canToggle || canValues

    const headerProps = { field, folderLabel, categoryId, canToggle, onInvalidate }

    const footer =
        showManageButton || showAddValueCta ? (
            <div className="space-y-2 border-t border-slate-100 bg-white px-2.5 py-2.5">
                {showAddValueCta ? (
                    <Link
                        href={manageCategoriesFieldValuesHref(categorySlug, field.id)}
                        className="flex items-center justify-center gap-1.5 text-[11px] font-medium text-[var(--wb-link)] hover:opacity-90"
                    >
                        <PlusIcon className="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden />
                        Add a value
                    </Link>
                ) : null}
                {showManageButton ? (
                    <Link
                        href={manageCategoriesHref(categorySlug)}
                        className="block w-full rounded-lg border border-slate-200/90 bg-white py-2 text-center text-[11px] font-semibold text-slate-800 shadow-sm transition hover:bg-slate-50"
                    >
                        Manage
                    </Link>
                ) : null}
            </div>
        ) : null

    if (compactNoOptions) {
        return (
            <div className="flex min-h-0 flex-1 flex-col">
                <FieldDetailHeader {...headerProps} />
                <QuickFilterControls
                    field={field}
                    categoryId={categoryId}
                    canToggle={canToggle}
                    onChanged={onInvalidate}
                />
                <div className="wb-panel-scroll min-h-0 flex-1 overflow-y-auto px-3 py-3">
                    <p className="text-[11px] leading-snug text-slate-600">
                        This field doesn&apos;t have any options yet.
                    </p>
                </div>
                {footer}
            </div>
        )
    }

    return (
        <div className="flex h-full max-h-[min(70vh,22rem)] min-h-0 flex-1 flex-col sm:max-h-none">
            <FieldDetailHeader {...headerProps} />
            <QuickFilterControls
                field={field}
                categoryId={categoryId}
                canToggle={canToggle}
                onChanged={onInvalidate}
            />
            <div className="wb-panel-scroll min-h-0 flex-1 overflow-y-auto px-2 py-2">
                {hasList ? (
                    <ul className="space-y-0.5 text-[12px] text-slate-700">
                        {preview.map((label, i) => (
                            <li
                                key={`${field.id}-v-${i}`}
                                className="truncate rounded px-2 py-1 hover:bg-slate-50"
                                title={label}
                            >
                                {label}
                            </li>
                        ))}
                        {rest > 0 ? (
                            <li className="px-2 py-1 text-[11px] italic text-slate-500">+{rest} more</li>
                        ) : null}
                    </ul>
                ) : (
                    <p className="px-2 text-[11px] leading-snug text-slate-500">
                        No fixed dropdown — editors type or pick dates/numbers directly on the item.
                    </p>
                )}
            </div>
            {footer}
        </div>
    )
}

/** Slim footer: compact custom-field lines (if any) + Manage folder when allowed. */
function FolderFlyoutFooter({
    categorySlug,
    customFields,
    canSeeManageLinks,
    canEditFieldDefinitions,
}) {
    if (!categorySlug) return null
    const hasCustom = customFields.length > 0
    if (!hasCustom && !canSeeManageLinks) return null

    return (
        <div className="border-t border-slate-100 px-2 py-1.5">
            {hasCustom ? (
                <ul className="mb-1 space-y-0.5">
                    {customFields.map((f) => (
                        <li
                            key={f.id}
                            className="flex items-center justify-between gap-2 py-0.5 text-[10px] leading-tight text-slate-600"
                        >
                            <span className="min-w-0 truncate" title={f.label}>
                                {f.label}
                            </span>
                            <Link
                                href={
                                    canEditFieldDefinitions
                                        ? tenantFieldHref(f.id)
                                        : manageCategoriesHref(categorySlug)
                                }
                                className="shrink-0 font-semibold text-[var(--wb-link)] hover:opacity-90"
                            >
                                Manage
                            </Link>
                        </li>
                    ))}
                </ul>
            ) : null}
            {canSeeManageLinks ? (
                <Link
                    href={manageCategoriesHref(categorySlug)}
                    className="block w-full rounded-md py-1 text-center text-[11px] font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-900"
                >
                    Manage folder
                </Link>
            ) : null}
        </div>
    )
}

function FieldListRow({ field, folderLabel, isActive, availableSection, clearHoverTimer, setActiveFieldId }) {
    const isSystem = field.is_system
    const rowLabel = folderScopedTypeDisplayTitle(folderLabel, field)
    return (
        <button
            type="button"
            aria-current={isActive ? 'true' : undefined}
            className={`flex w-full min-w-0 items-center gap-2 rounded-lg px-2 py-1.5 text-left transition-colors ${
                isActive
                    ? 'bg-slate-100 text-[13px] font-semibold text-slate-900 shadow-sm ring-1 ring-inset ring-[color:var(--wb-accent)]/45'
                    : availableSection
                      ? 'py-1 text-[11px] font-normal text-slate-400 hover:bg-slate-50/70'
                      : isSystem
                        ? 'text-[13px] font-semibold text-slate-900 hover:bg-slate-50/90'
                        : 'text-[13px] font-semibold text-slate-900 hover:bg-slate-50/90'
            }`}
            onMouseEnter={() => {
                clearHoverTimer()
                setActiveFieldId(field.id)
            }}
            onFocus={() => setActiveFieldId(field.id)}
            onClick={() => setActiveFieldId(field.id)}
        >
            <span className="min-w-0 flex-1 truncate" title={rowLabel}>
                {rowLabel}
            </span>
            <ChevronRightIcon
                className={`h-4 w-4 shrink-0 ${
                    isActive
                        ? 'text-[var(--wb-accent)] opacity-100'
                        : availableSection
                          ? 'text-slate-300/80 opacity-60'
                          : isSystem
                            ? 'text-slate-400 opacity-70'
                            : 'text-[var(--wb-accent)] opacity-70'
                }`}
                aria-hidden
            />
        </button>
    )
}

const PANEL_CLASS =
    'z-[240] overflow-visible [--anchor-gap:8px] [--anchor-offset:0px] data-closed:scale-95 data-closed:opacity-0'

/**
 * Nested flyout: all enabled fields open a values + actions column (hover/click).
 * Anchored bottom-start so the panel grows rightward without shifting the left origin.
 */
export default function FolderSchemaHelp({ category, className = '', triggerClassName = null }) {
    const { can } = usePermission()
    const cacheRef = useRef({})
    const hoverTimerRef = useRef(null)
    const [activeFieldId, setActiveFieldId] = useState(null)
    const [payload, setPayload] = useState(null)
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState(null)

    const canSeeManageLinks = useMemo(
        () =>
            can('metadata.registry.view') ||
            can('metadata.tenant.visibility.manage') ||
            can('metadata.tenant.field.manage') ||
            can('brand_categories.manage'),
        [can]
    )

    const canAddField = useMemo(() => can('metadata.tenant.field.manage'), [can])

    /** Toggle folder field visibility or add fields → settings icon; view-only registry → info icon */
    const triggerShowsSettings = useMemo(
        () => can('metadata.tenant.visibility.manage') || can('metadata.tenant.field.manage'),
        [can]
    )

    const brandWorkbenchPkg = useBrandWorkbenchChrome()
    const { auth, company } = usePage().props
    const workbenchChromeVars = useMemo(() => {
        if (brandWorkbenchPkg?.vars) {
            return brandWorkbenchPkg.vars
        }

        return buildBrandWorkbenchChromePackage(auth?.activeBrand, company).vars
    }, [
        brandWorkbenchPkg,
        auth?.activeBrand,
        auth?.activeBrand?.id,
        company?.id,
        company?.primary_color,
    ])

    const load = useCallback(async () => {
        if (!category?.id) return
        const cached = cacheRef.current[category.id]
        if (cached) {
            setPayload(cached)
            setError(null)
            return
        }
        setLoading(true)
        setError(null)
        try {
            const res = await fetch(`/app/api/tenant/metadata/categories/${category.id}/folder-schema`, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
            const data = await res.json().catch(() => ({}))
            if (!res.ok) {
                throw new Error(data.error || data.message || 'Could not load folder schema')
            }
            cacheRef.current[category.id] = data
            setPayload(data)
        } catch (e) {
            setError(e.message || 'Could not load folder schema')
            setPayload(null)
        } finally {
            setLoading(false)
        }
    }, [category?.id])

    const invalidateAndReload = useCallback(() => {
        if (category?.id) delete cacheRef.current[category.id]
        void load()
    }, [category?.id, load])

    const {
        enabledFieldsSorted,
        availableFieldsSorted,
        listableFields,
        hasAvailableFields,
        canToggleFolderField,
    } = useMemo(() => {
        if (!payload) {
            return {
                enabledFieldsSorted: [],
                availableFieldsSorted: [],
                listableFields: [],
                hasAvailableFields: false,
                canToggleFolderField: false,
            }
        }
        const enabled = sortFieldsForDisplay(
            (payload.fields_on || []).filter((f) => !f.is_automated)
        )
        const available = sortFieldsForDisplay(
            (payload.fields_off || []).filter((f) => !f.is_automated)
        )
        const listable = [...enabled, ...available]
        return {
            enabledFieldsSorted: enabled,
            availableFieldsSorted: available,
            listableFields: listable,
            hasAvailableFields: available.length > 0,
            canToggleFolderField: Boolean(payload.permissions?.can_toggle_folder_field),
        }
    }, [payload])

    const customFieldsOnFolder = useMemo(
        () => enabledFieldsSorted.filter((f) => !f.is_system),
        [enabledFieldsSorted]
    )

    const canEditFieldDefinitions = Boolean(payload?.permissions?.can_edit_definitions)

    const activeField = useMemo(
        () => listableFields.find((x) => x.id === activeFieldId) ?? null,
        [listableFields, activeFieldId]
    )

    const activeFieldCompact = useMemo(() => {
        if (!activeField) return false
        const isListType = activeField.field_type === 'select' || activeField.field_type === 'multiselect'
        const hasList = activeField.values_expandable && activeField.options_total > 0
        return isListType && !hasList
    }, [activeField])

    const clearHoverTimer = useCallback(() => {
        if (hoverTimerRef.current) {
            clearTimeout(hoverTimerRef.current)
            hoverTimerRef.current = null
        }
    }, [])

    const scheduleClearSubmenu = useCallback(() => {
        clearHoverTimer()
        hoverTimerRef.current = setTimeout(() => setActiveFieldId(null), 140)
    }, [clearHoverTimer])

    useEffect(() => () => clearHoverTimer(), [clearHoverTimer])

    useEffect(() => {
        if (!payload) return
        if (listableFields.length === 0) {
            setActiveFieldId(null)
            return
        }
        setActiveFieldId((id) => {
            if (id != null && listableFields.some((f) => f.id === id)) return id
            return listableFields[0].id
        })
    }, [payload, listableFields])

    if (!category?.id) {
        return null
    }

    const defaultTriggerClass =
        'rounded p-0.5 text-slate-400 opacity-0 transition-all hover:bg-slate-200/80 hover:text-slate-700 focus:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--wb-ring)] group-hover:opacity-100 data-[open]:opacity-100 data-[open]:bg-slate-200 data-[open]:text-slate-800 data-[open]:shadow-sm data-[open]:ring-2 data-[open]:ring-[color:var(--wb-ring)] data-[open]:ring-offset-0'

    const categorySlug = payload?.category?.slug ?? category?.slug ?? ''
    const folderLabel = payload?.category?.name ?? category.name

    const triggerCombinedClass = triggerClassName
        ? `${triggerClassName} data-[open]:opacity-100 data-[open]:ring-2 data-[open]:ring-[color:var(--wb-ring)] data-[open]:ring-offset-0 data-[open]:bg-white/15`
        : defaultTriggerClass

    return (
        <Popover className={`relative ${className}`}>
            <PopoverButton
                type="button"
                onClick={(e) => {
                    e.stopPropagation()
                    setActiveFieldId(null)
                    void load()
                }}
                className={triggerCombinedClass}
                title={triggerShowsSettings ? 'Folder filters — manage' : 'Folder filters'}
                aria-label={
                    triggerShowsSettings
                        ? `Folder filters and settings for ${category.name}`
                        : `Folder filters for ${category.name} (view only)`
                }
            >
                {triggerShowsSettings ? (
                    <Cog6ToothIcon className="h-4 w-4" aria-hidden />
                ) : (
                    <InformationCircleIcon className="h-4 w-4" aria-hidden />
                )}
            </PopoverButton>
            <PopoverPanel transition anchor="bottom start" className={PANEL_CLASS}>
                {/* Shadow on an outer shell so it isn’t clipped by overflow:hidden on the card body */}
                <div
                    className={`brand-workbench-theme overflow-visible rounded-xl shadow-[0_22px_56px_-14px_rgba(15,23,42,0.35),0_10px_28px_-8px_rgba(15,23,42,0.2),0_2px_8px_-2px_rgba(15,23,42,0.12)] ring-1 ring-slate-900/8 transition duration-100 ease-out ${
                        activeField
                            ? 'w-[min(calc(100vw-1rem),30rem)] sm:w-[30rem]'
                            : 'w-[min(14rem,calc(100vw-1rem))] sm:w-56'
                    }`}
                    style={workbenchChromeVars}
                    onMouseEnter={clearHoverTimer}
                    onMouseLeave={scheduleClearSubmenu}
                >
                    <div
                        className={`flex min-h-0 max-h-[min(72vh,24rem)] min-w-0 overflow-x-hidden rounded-xl border border-slate-200/70 bg-white ${
                            activeField ? 'flex-col sm:flex-row' : 'flex-col'
                        }`}
                    >
                    <div
                        className={`flex min-w-0 flex-col sm:w-56 sm:shrink-0 ${
                            activeField
                                ? 'max-h-[38%] min-h-0 overflow-hidden rounded-t-xl border-b border-slate-100 sm:max-h-none sm:rounded-l-xl sm:rounded-tr-none sm:rounded-br-none sm:border-b-0 sm:border-r'
                                : 'w-full min-w-0 overflow-hidden rounded-xl'
                        }`}
                    >
                        <div className="border-b border-slate-100 bg-slate-50/60 px-3 py-3">
                            <p className="truncate text-xs font-bold tracking-tight text-slate-900">{folderLabel}</p>
                            <p className="mt-1 text-[9px] leading-snug text-slate-500">
                                {hasAvailableFields
                                    ? 'Fields on the asset form are listed first, then a divider, then other folder options.'
                                    : 'These fields are enabled for this folder and appear on the asset metadata form.'}
                            </p>
                            <p className="mt-1 text-[10px] text-slate-500">
                                {loading && !payload
                                    ? 'Loading…'
                                    : payload
                                      ? `${enabledFieldsSorted.length} on form${
                                            hasAvailableFields
                                                ? ` · ${availableFieldsSorted.length} available`
                                                : ''
                                        }`
                                      : '—'}
                            </p>
                        </div>
                        <div className="wb-panel-scroll min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto py-1.5">
                            {error ? (
                                <p className="px-3 py-2 text-[11px] text-red-600">{error}</p>
                            ) : payload &&
                              enabledFieldsSorted.length === 0 &&
                              availableFieldsSorted.length === 0 ? (
                                <p className="px-3 py-2 text-[11px] text-slate-500">No fields on the asset form.</p>
                            ) : (
                                <>
                                    {payload && enabledFieldsSorted.length === 0 ? (
                                        <p className="px-3 pb-1 text-[11px] text-slate-500">
                                            No fields on the asset form yet.
                                        </p>
                                    ) : null}
                                    {hasAvailableFields && enabledFieldsSorted.length > 0 ? (
                                        <div className="px-2 pb-0.5 pt-1">
                                            <p className="px-1 text-[9px] font-semibold uppercase tracking-wider text-slate-700">
                                                On asset form
                                            </p>
                                        </div>
                                    ) : null}
                                    {enabledFieldsSorted.map((f) => (
                                        <FieldListRow
                                            key={f.id}
                                            field={f}
                                            folderLabel={folderLabel}
                                            isActive={activeFieldId === f.id}
                                            availableSection={false}
                                            clearHoverTimer={clearHoverTimer}
                                            setActiveFieldId={setActiveFieldId}
                                        />
                                    ))}
                                    {hasAvailableFields ? (
                                        <div className="px-2 pt-2" role="presentation">
                                            <hr className="border-slate-200/90" />
                                            <p className="mt-2 px-1 text-[9px] font-semibold uppercase tracking-wider text-slate-400">
                                                Available for this folder
                                            </p>
                                            <p className="mt-0.5 px-1 text-[9px] leading-snug text-slate-400">
                                                {canToggleFolderField
                                                    ? 'Off for this folder, or on in Manage but hidden from the asset form — use the switch in the panel.'
                                                    : 'Not on the asset form for this folder, or turned off for this folder in Manage.'}
                                            </p>
                                        </div>
                                    ) : null}
                                    {hasAvailableFields
                                        ? availableFieldsSorted.map((f) => (
                                              <FieldListRow
                                                  key={f.id}
                                                  field={f}
                                                  folderLabel={folderLabel}
                                                  isActive={activeFieldId === f.id}
                                                  availableSection
                                                  clearHoverTimer={clearHoverTimer}
                                                  setActiveFieldId={setActiveFieldId}
                                              />
                                          ))
                                        : null}
                                </>
                            )}
                            {canAddField && categorySlug ? (
                                <Link
                                    href={manageCategoriesNewFieldHref(categorySlug)}
                                    onMouseEnter={clearHoverTimer}
                                    className="flex w-full items-center gap-1.5 rounded-lg px-2 py-1.5 text-left text-[13px] font-semibold text-[var(--wb-accent)] transition-colors hover:bg-slate-50/90"
                                >
                                    <PlusIcon className="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden />
                                    Add new field
                                </Link>
                            ) : null}
                        </div>
                        {payload ? (
                            <FolderFlyoutFooter
                                categorySlug={categorySlug}
                                customFields={customFieldsOnFolder}
                                canSeeManageLinks={canSeeManageLinks}
                                canEditFieldDefinitions={canEditFieldDefinitions}
                            />
                        ) : null}
                    </div>
                    {activeField && payload ? (
                        <div
                            className={`flex min-w-0 flex-1 flex-col overflow-x-hidden overflow-visible rounded-b-xl border-t border-slate-100 sm:w-56 sm:flex-none sm:rounded-bl-none sm:rounded-b-none sm:rounded-r-xl sm:rounded-t-none sm:border-l sm:border-t-0 ${
                                activeFieldCompact
                                    ? 'min-h-0 sm:h-auto sm:min-h-0'
                                    : 'min-h-[11rem] sm:h-full sm:min-h-[15rem]'
                            }`}
                        >
                            <SubmenuPanel
                                field={activeField}
                                categoryId={payload.category.id}
                                categorySlug={categorySlug}
                                folderLabel={folderLabel}
                                permissions={payload.permissions}
                                onInvalidate={invalidateAndReload}
                            />
                        </div>
                    ) : null}
                    </div>
                </div>
            </PopoverPanel>
        </Popover>
    )
}
