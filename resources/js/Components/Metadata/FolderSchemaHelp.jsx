import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from '@inertiajs/react'
import { Popover, PopoverButton, PopoverPanel, Switch } from '@headlessui/react'
import {
    ChevronRightIcon,
    InformationCircleIcon,
    PencilSquareIcon,
    PlusIcon,
    Squares2X2Icon,
} from '@heroicons/react/24/outline'
import { usePermission } from '../../hooks/usePermission'

const MANAGE_VALUES_URL =
    typeof route === 'function' ? route('manage.values') : '/app/manage/values'
const MANAGE_CATEGORIES_URL =
    typeof route === 'function' ? route('manage.categories') : '/app/manage/categories'

function manageCategoriesHref(slug) {
    if (!slug) return MANAGE_CATEGORIES_URL
    return `${MANAGE_CATEGORIES_URL}?${new URLSearchParams({ category: slug })}`
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

function sortFieldsForDisplay(fields) {
    const tier = (f) => {
        if (!f.is_system) return 0
        if (f.is_automated) return 3
        if (isBroadOrganizingField(f)) return 2
        return 1
    }
    return [...fields].sort((a, b) => {
        const ta = tier(a)
        const tb = tier(b)
        if (ta !== tb) return ta - tb
        return String(a.label || '').localeCompare(String(b.label || ''), undefined, { sensitivity: 'base' })
    })
}

function FieldToggle({ field, categoryId, canToggle, onChanged }) {
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
            className="flex items-center justify-between gap-2 rounded-md px-2 py-1.5"
            style={{ backgroundColor: 'color-mix(in srgb, var(--wb-accent) 6%, #f8fafc)' }}
        >
            <span className="text-[11px] text-slate-600">On for this folder</span>
            <Switch
                checked={field.enabled_for_folder}
                disabled={busy}
                onChange={handleChange}
                className={`relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--wb-ring)] focus-visible:ring-offset-1 disabled:opacity-50 ${
                    field.enabled_for_folder ? 'bg-[var(--wb-accent)]' : 'bg-slate-300'
                }`}
            >
                <span className="sr-only">Use {field.label} on this folder</span>
                <span
                    aria-hidden
                    className={`pointer-events-none inline-block h-4 w-4 translate-x-0 transform rounded-full bg-white shadow transition ${
                        field.enabled_for_folder ? 'translate-x-4' : 'translate-x-0'
                    }`}
                />
            </Switch>
        </div>
    )
}

function SubmenuPanel({ field, categoryId, categorySlug, permissions, onInvalidate }) {
    const hasList = field.values_expandable && field.options_total > 0
    const isListType = field.field_type === 'select' || field.field_type === 'multiselect'
    const preview = field.options_preview || []
    const rest = Math.max(0, field.options_total - preview.length)
    const canToggle = permissions?.can_toggle_folder_field
    const canEditDef = permissions?.can_edit_definitions
    const canValues = permissions?.can_manage_option_values

    const showValuesLink =
        canValues && isListType && !field.option_editing_restricted && (hasList || field.options_total === 0)
    const showFieldDefLink = canEditDef && !field.is_system

    return (
        <div className="flex h-full max-h-[min(70vh,22rem)] min-h-0 flex-1 flex-col sm:max-h-none">
            <div
                className="border-b px-3 py-2"
                style={{
                    borderColor: 'color-mix(in srgb, var(--wb-accent) 22%, #e2e8f0)',
                    backgroundColor: 'color-mix(in srgb, var(--wb-accent) 5%, white)',
                }}
            >
                <p className="truncate text-sm font-semibold text-slate-900" title={field.label}>
                    {field.label}
                </p>
                <p className="mt-0.5 text-[11px] text-slate-500">
                    {shortTypeLabel(field)}
                    {field.is_automated ? ' · Auto' : ''}
                    <span className="font-medium text-[var(--wb-accent)]"> · Custom</span>
                </p>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto px-2 py-2">
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
                ) : isListType ? (
                    <p className="px-2 text-[11px] text-slate-500">No list values configured yet.</p>
                ) : (
                    <p className="px-2 text-[11px] leading-snug text-slate-500">
                        No fixed dropdown — editors type or pick dates/numbers directly on the item.
                    </p>
                )}
            </div>
            <div className="space-y-1.5 border-t border-slate-100 p-2">
                <FieldToggle
                    field={field}
                    categoryId={categoryId}
                    canToggle={canToggle}
                    onChanged={onInvalidate}
                />
                <div className="flex flex-col gap-1">
                    {showValuesLink ? (
                        <Link
                            href={MANAGE_VALUES_URL}
                            className="inline-flex items-center justify-center gap-1.5 rounded-md bg-[var(--wb-accent)] px-2 py-1.5 text-center text-[11px] font-semibold text-white hover:opacity-95"
                        >
                            <Squares2X2Icon className="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden />
                            Values
                        </Link>
                    ) : null}
                    {showFieldDefLink ? (
                        <Link
                            href={tenantFieldHref(field.id)}
                            className="inline-flex items-center justify-center gap-1.5 rounded-md border border-slate-200 bg-white px-2 py-1.5 text-center text-[11px] font-semibold text-slate-700 hover:bg-slate-50"
                        >
                            <PencilSquareIcon className="h-3.5 w-3.5 shrink-0" aria-hidden />
                            Edit field
                        </Link>
                    ) : null}
                    {canEditDef || permissions?.can_toggle_folder_field ? (
                        <Link
                            href={manageCategoriesHref(categorySlug)}
                            className="block text-center text-[10px] font-medium text-[var(--wb-link)] hover:opacity-90"
                        >
                            Folder in Manage
                        </Link>
                    ) : null}
                </div>
            </div>
        </div>
    )
}

const PANEL_CLASS =
    'z-[240] [--anchor-gap:8px] [--anchor-offset:0px] data-closed:scale-95 data-closed:opacity-0'

/**
 * Flyout: custom fields expand to values + actions; built-in fields are subtle text only.
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

    const sortedFields = useMemo(() => {
        if (!payload) return []
        return sortFieldsForDisplay([...(payload.fields_on || []), ...(payload.fields_on_automated || [])])
    }, [payload])

    const customFields = useMemo(() => sortedFields.filter((f) => !f.is_system), [sortedFields])
    const systemFields = useMemo(() => sortedFields.filter((f) => f.is_system), [sortedFields])

    const activeField = useMemo(() => {
        const f = sortedFields.find((x) => x.id === activeFieldId)
        if (!f || f.is_system) return null
        return f
    }, [sortedFields, activeFieldId])

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
        if (activeFieldId == null) return
        const hit = sortedFields.find((x) => x.id === activeFieldId)
        if (hit?.is_system) setActiveFieldId(null)
    }, [activeFieldId, sortedFields])

    if (!category?.id) {
        return null
    }

    const defaultTriggerClass =
        'rounded p-0.5 text-slate-400 opacity-0 transition-opacity hover:bg-slate-200/80 hover:text-slate-700 focus:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--wb-ring)] group-hover:opacity-100'

    const categorySlug = payload?.category?.slug ?? category?.slug ?? ''
    const folderLabel = payload?.category?.name ?? category.name

    const panelBorder = { borderColor: 'color-mix(in srgb, var(--wb-accent) 24%, #e2e8f0)' }
    const headerBar = {
        borderColor: 'color-mix(in srgb, var(--wb-accent) 20%, #e2e8f0)',
        backgroundColor: 'color-mix(in srgb, var(--wb-accent) 6%, white)',
    }

    return (
        <Popover className={`relative ${className}`}>
            <PopoverButton
                type="button"
                onClick={(e) => {
                    e.stopPropagation()
                    setActiveFieldId(null)
                    void load()
                }}
                className={triggerClassName ?? defaultTriggerClass}
                title="Folder fields"
                aria-label={`Folder fields for ${category.name}`}
            >
                <InformationCircleIcon className="h-4 w-4" aria-hidden />
            </PopoverButton>
            <PopoverPanel transition anchor="bottom start" className={PANEL_CLASS}>
                <div
                    className={`flex max-h-[min(70vh,22rem)] overflow-hidden rounded-xl border bg-white shadow-2xl ring-1 transition duration-100 ease-out ${
                        activeField
                            ? 'w-[min(calc(100vw-1rem),25rem)] flex-col sm:w-[25rem] sm:flex-row'
                            : 'w-[min(12.5rem,calc(100vw-1rem))] flex-col sm:w-52'
                    }`}
                    style={{ ...panelBorder, boxShadow: '0 12px 40px -12px color-mix(in srgb, var(--wb-accent) 18%, #0f172a)' }}
                    onMouseEnter={clearHoverTimer}
                    onMouseLeave={scheduleClearSubmenu}
                >
                    <div
                        className={`flex min-w-0 flex-col sm:w-48 sm:shrink-0 ${activeField ? 'max-h-[38%] border-b sm:max-h-none sm:border-b-0 sm:border-r' : 'w-full'}`}
                        style={
                            activeField
                                ? { borderColor: 'color-mix(in srgb, var(--wb-accent) 18%, #e2e8f0)' }
                                : undefined
                        }
                    >
                        <div className="border-b px-3 py-2.5" style={headerBar}>
                            <p className="truncate text-xs font-bold tracking-tight text-slate-900">{folderLabel}</p>
                            <p className="mt-1 text-[9px] leading-snug text-slate-500">
                                These fields are enabled for this folder in Manage.
                            </p>
                            <p className="mt-1 text-[10px] text-slate-500">
                                {loading && !payload
                                    ? 'Loading…'
                                    : payload
                                      ? `${sortedFields.length} field${sortedFields.length === 1 ? '' : 's'}`
                                      : '—'}
                            </p>
                        </div>
                        <div className="min-h-0 flex-1 overflow-y-auto py-1">
                            {error ? (
                                <p className="px-3 py-2 text-[11px] text-red-600">{error}</p>
                            ) : sortedFields.length === 0 && payload ? (
                                <p className="px-3 py-2 text-[11px] text-slate-500">No fields enabled.</p>
                            ) : (
                                <>
                                    {customFields.map((f) => {
                                        const isActive = activeFieldId === f.id
                                        return (
                                            <button
                                                key={`c-${f.id}`}
                                                type="button"
                                                className={`flex w-full items-center gap-1 px-2 py-1.5 text-left text-[13px] ${
                                                    isActive
                                                        ? 'font-medium text-slate-900'
                                                        : 'text-slate-800 hover:bg-slate-50'
                                                }`}
                                                style={
                                                    isActive
                                                        ? {
                                                              backgroundColor:
                                                                  'color-mix(in srgb, var(--wb-accent) 12%, white)',
                                                          }
                                                        : undefined
                                                }
                                                onMouseEnter={() => {
                                                    clearHoverTimer()
                                                    setActiveFieldId(f.id)
                                                }}
                                                onFocus={() => setActiveFieldId(f.id)}
                                                onClick={() => setActiveFieldId(f.id)}
                                            >
                                                <span className="min-w-0 flex-1 truncate">{f.label}</span>
                                                <ChevronRightIcon
                                                    className="h-4 w-4 shrink-0 text-[var(--wb-accent)] opacity-70"
                                                    aria-hidden
                                                />
                                            </button>
                                        )
                                    })}
                                    {systemFields.length > 0 && customFields.length > 0 ? (
                                        <div
                                            className="mx-2 my-1.5 border-t border-slate-200/90"
                                            aria-hidden
                                        />
                                    ) : null}
                                    {systemFields.length > 0 ? (
                                        <div className="px-2 pb-1 pt-0.5">
                                            <p className="px-1 pb-1 text-[9px] font-semibold uppercase tracking-wide text-slate-400">
                                                Built-in
                                            </p>
                                            <ul className="space-y-0.5">
                                                {systemFields.map((f) => (
                                                    <li
                                                        key={`s-${f.is_automated ? 'a-' : ''}${f.id}`}
                                                        className="px-1 py-0.5 text-[11px] leading-snug text-slate-400"
                                                        title={`${f.label} · ${shortTypeLabel(f)}`}
                                                    >
                                                        <span className="text-slate-500">{f.label}</span>
                                                        {f.is_automated ? (
                                                            <span className="text-slate-400"> · auto</span>
                                                        ) : null}
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    ) : null}
                                </>
                            )}
                        </div>
                        <div
                            className="space-y-1.5 border-t px-2 py-2"
                            style={{
                                borderColor: 'color-mix(in srgb, var(--wb-accent) 14%, #e2e8f0)',
                                backgroundColor: 'color-mix(in srgb, var(--wb-accent) 4%, white)',
                            }}
                        >
                            {canAddField && categorySlug ? (
                                <Link
                                    href={manageCategoriesNewFieldHref(categorySlug)}
                                    className="flex w-full items-center justify-center gap-1.5 rounded-lg bg-[var(--wb-accent)] px-2 py-2 text-center text-[11px] font-semibold text-white shadow-sm hover:opacity-95"
                                >
                                    <PlusIcon className="h-3.5 w-3.5 shrink-0" aria-hidden />
                                    New field
                                </Link>
                            ) : null}
                            {canAddField && categorySlug ? (
                                <p className="px-0.5 text-center text-[9px] leading-snug text-slate-500">
                                    Opens Manage with this folder — name your field in the form.
                                </p>
                            ) : null}
                            {canSeeManageLinks ? (
                                <Link
                                    href={manageCategoriesHref(categorySlug)}
                                    className="block truncate text-center text-[10px] font-semibold text-[var(--wb-link)] hover:opacity-90"
                                >
                                    Manage folder
                                </Link>
                            ) : null}
                        </div>
                    </div>
                    {activeField && payload ? (
                        <div
                            className="flex min-h-[11rem] min-w-0 flex-1 flex-col border-t sm:h-full sm:min-h-[15rem] sm:w-52 sm:flex-none sm:border-l sm:border-t-0"
                            style={{ borderColor: 'color-mix(in srgb, var(--wb-accent) 18%, #e2e8f0)' }}
                        >
                            <SubmenuPanel
                                field={activeField}
                                categoryId={payload.category.id}
                                categorySlug={categorySlug}
                                permissions={payload.permissions}
                                onInvalidate={invalidateAndReload}
                            />
                        </div>
                    ) : null}
                </div>
            </PopoverPanel>
        </Popover>
    )
}
