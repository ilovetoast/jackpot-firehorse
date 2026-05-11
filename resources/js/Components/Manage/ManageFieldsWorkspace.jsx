import { useState, useEffect, useMemo, useCallback, useRef, useId } from 'react'
import { router, Link } from '@inertiajs/react'
import {
    ArrowPathIcon,
    ChevronDownIcon,
    Cog6ToothIcon,
    FolderIcon,
    LockClosedIcon,
    PhotoIcon,
    PlusIcon,
} from '@heroicons/react/24/outline'
import ConfirmDialog from '../ConfirmDialog'
import MetadataFieldModal from '../MetadataFieldModal'
import { productButtonPrimary } from '../../components/brand-workspace/brandWorkspaceTokens'
import { canUseSearch, canUseSidebarFilter } from '../../utils/metadataFilterEligibility'

const CORE_FIELD_KEYS = ['collection', 'tags']

const MANAGE_CATEGORIES_URL =
    typeof route === 'function' ? route('manage.categories') : '/app/manage/categories'

function getCsrfToken() {
    if (typeof document === 'undefined') return ''
    return document.querySelector('meta[name="csrf-token"]')?.content || ''
}

/**
 * Table title for Manage → Fields. Only the folder's (Category model) canonical primary type field
 * (type_field.field_key, e.g. photo_type) should read as "Type". Other keys ending in
 * _type (environment_type, subject_type, execution_video_type, …) must use their
 * system labels so they do not all collapse to duplicate "Type" rows.
 */
function fieldTitle(field, primaryTypeFieldKey = null) {
    const k = field.key ? String(field.key) : ''
    if (k.endsWith('_type')) {
        if (primaryTypeFieldKey && k === primaryTypeFieldKey) {
            return 'Type'
        }
        return field.label || field.system_label || k.replace(/_type$/, '').replace(/_/g, ' ') || 'Field'
    }
    return field.label || field.system_label || k || 'Unnamed Field'
}

function normalizeFieldLabelCompare(s) {
    return String(s || '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, ' ')
}

/** True if this folder’s field list already includes a match (by row title or snake_case key). */
function quickSuggestionIsTaken(suggestionLabel, rows, primaryTypeKey) {
    const target = normalizeFieldLabelCompare(suggestionLabel)
    if (!target) return false
    const keyGuess = target.replace(/ /g, '_')
    for (const row of rows) {
        const f = row.field
        const k = String(f.key || f.field_key || '').toLowerCase()
        if (k && k === keyGuess) return true
        if (normalizeFieldLabelCompare(fieldTitle(f, primaryTypeKey)) === target) return true
    }
    return false
}

function sortRowsByFieldName(rows, primaryTypeKey) {
    return [...rows].sort((a, b) =>
        fieldTitle(a.field, primaryTypeKey).localeCompare(fieldTitle(b.field, primaryTypeKey), undefined, {
            sensitivity: 'base',
        })
    )
}

/** Collapsible field list — parent should set `key={...}` when the folder changes so defaults reapply. */
function FieldAccordion({ panelId, title, count, defaultExpanded, subtle, children }) {
    const [open, setOpen] = useState(defaultExpanded)
    return (
        <div
            className={`overflow-hidden rounded-xl border ${subtle ? 'border-slate-200/80 bg-slate-50/25' : 'border-slate-200/90 bg-white shadow-sm'}`}
        >
            <button
                type="button"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => setOpen((v) => !v)}
                className="flex w-full items-center justify-between gap-2 px-3 py-2.5 text-left transition hover:bg-slate-50/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--wb-ring)] focus-visible:ring-offset-2 sm:px-4"
            >
                <span className="min-w-0 text-sm font-semibold text-slate-900">
                    {title}
                    <span className="font-normal text-slate-500"> · {count}</span>
                </span>
                <ChevronDownIcon
                    className={`h-5 w-5 shrink-0 text-slate-500 transition-transform duration-200 ${
                        open ? 'rotate-180' : ''
                    }`}
                    aria-hidden
                />
            </button>
            <div
                id={panelId}
                role="region"
                className={open ? 'border-t border-slate-200/80 px-3 pb-3 pt-2 sm:px-4' : 'hidden'}
            >
                {children}
            </div>
        </div>
    )
}

/** Preset starters: label + default list type (dropdown can be changed per row before click). */
const QUICK_FIELD_EXAMPLES = [
    { label: 'SKU', defaultType: 'select' },
    { label: 'Product', defaultType: 'multiselect', hint: 'Multi-select · channels, markets, regions' },
    { label: 'Department', defaultType: 'select' },
    { label: 'Usage Rights', defaultType: 'select' },
    { label: 'Expiration Date', defaultType: 'select' },
    { label: 'Subject', defaultType: 'select' },
]

const QUICK_TYPE_OPTIONS = [
    { value: 'select', short: 'Single-select' },
    { value: 'multiselect', short: 'Multi-select' },
]

const QUICK_GUIDE_PANEL_ID = 'manage-fields-quick-guide-panel'

/** Collapsible 1-2-3 for Manage → Fields; starts closed with a short “learn more” invite. */
function ManageFieldsQuickGuide() {
    const [expanded, setExpanded] = useState(false)

    const steps = [
        {
            n: 1,
            Icon: FolderIcon,
            title: 'Folder first',
            body: (
                <>
                    Fields belong to one folder. Add or rename folders in{' '}
                    <Link
                        href={MANAGE_CATEGORIES_URL}
                        className="font-medium text-[var(--wb-link)] underline decoration-[color:color-mix(in_srgb,var(--wb-accent)_45%,transparent)] underline-offset-2 hover:opacity-90"
                    >
                        Manage → Categories
                    </Link>
                    , then pick it in this hub.
                </>
            ),
        },
        {
            n: 2,
            Icon: PhotoIcon,
            title: 'Create fields',
            body: (
                <>
                    <span className="font-medium text-slate-800">Create field</span> or{' '}
                    <span className="font-medium text-slate-800">Add field</span>, then pick types in the editor (text,
                    lists, dates, yes/no, …). Metadata describes assets; thumbnails come from the file.
                </>
            ),
        },
        {
            n: 3,
            Icon: Cog6ToothIcon,
            title: 'Turn on & configure',
            body: (
                <>
                    Set each row <span className="font-medium text-slate-800">On</span> so it shows on upload, in the
                    asset drawer, and in filters where supported. <span className="font-medium text-slate-800">Configure</span>{' '}
                    edits list values, descriptions, and visibility.
                </>
            ),
        },
    ]

    return (
        <section
            className="overflow-hidden rounded-xl border border-[color:color-mix(in_srgb,var(--wb-accent)_22%,#e2e8f0)] bg-gradient-to-br from-[color:color-mix(in_srgb,var(--wb-accent)_7%,white)] via-white to-slate-50/90 shadow-sm ring-1 ring-black/[0.02]"
            aria-label="Quick guide: folders and fields"
        >
            <button
                type="button"
                id="manage-fields-quick-guide-trigger"
                aria-expanded={expanded}
                aria-controls={QUICK_GUIDE_PANEL_ID}
                onClick={() => setExpanded((v) => !v)}
                className="flex w-full items-center justify-between gap-3 px-3 py-3 text-left transition hover:bg-[color:color-mix(in_srgb,var(--wb-accent)_4%,white)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--wb-ring)] focus-visible:ring-offset-2 sm:px-4 sm:py-3.5"
            >
                <div className="min-w-0">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-[var(--wb-accent)]">
                        Quick guide
                    </p>
                    <p className="mt-0.5 text-sm font-semibold text-slate-900 sm:text-base">
                        How folders &amp; fields work
                    </p>
                    <p className="mt-0.5 text-xs leading-snug text-slate-600">
                        {expanded
                            ? 'Three short steps below — click to collapse anytime.'
                            : 'New here? Expand for a 3-step walkthrough (folder → create → turn on).'}
                    </p>
                </div>
                <ChevronDownIcon
                    className={`h-5 w-5 shrink-0 text-slate-500 transition-transform duration-200 ${
                        expanded ? 'rotate-180' : ''
                    }`}
                    aria-hidden
                />
            </button>

            <div
                id={QUICK_GUIDE_PANEL_ID}
                role="region"
                aria-labelledby="manage-fields-quick-guide-trigger"
                className={expanded ? 'border-t border-slate-200/80 px-3 pb-3 pt-1 sm:px-4 sm:pb-4' : 'hidden'}
            >
                <h4 className="sr-only" id="manage-fields-quick-guide-title">
                    Folders and fields in three steps
                </h4>
                <ol className="mt-2 grid list-none gap-2.5 p-0 sm:grid-cols-3 sm:gap-3">
                    {steps.map(({ n, Icon, title, body }) => (
                        <li
                            key={n}
                            className="flex gap-2.5 rounded-lg border border-slate-200/80 bg-white/95 p-2.5 shadow-sm sm:flex-col sm:gap-2 sm:p-3"
                        >
                            <div className="flex shrink-0 items-center gap-2 sm:flex-col sm:items-start">
                                <span
                                    className="flex h-6 w-6 items-center justify-center rounded-full bg-[var(--wb-accent)] text-[11px] font-bold text-[var(--wb-on-accent)] shadow-sm sm:h-7 sm:w-7 sm:text-xs"
                                    aria-hidden
                                >
                                    {n}
                                </span>
                                <span
                                    className="flex h-8 w-8 items-center justify-center rounded-lg bg-[color:color-mix(in_srgb,var(--wb-accent)_14%,white)] text-[var(--wb-accent)] sm:h-9 sm:w-9"
                                    aria-hidden
                                >
                                    <Icon className="h-4 w-4 sm:h-5 sm:w-5" />
                                </span>
                            </div>
                            <div className="min-w-0 flex-1 pt-0.5 sm:pt-0">
                                <p className="text-sm font-semibold leading-tight text-slate-900">{title}</p>
                                <p className="mt-1 text-[11px] leading-snug text-slate-600 sm:text-xs">{body}</p>
                            </div>
                        </li>
                    ))}
                </ol>
            </div>
        </section>
    )
}

function QuickFieldTypeSelect({ id, value, onChange, disabled, compact }) {
    return (
        <select
            id={id}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            disabled={disabled}
            aria-label="Field list type"
            className={`shrink-0 rounded-lg border border-slate-200/90 bg-white font-medium text-slate-700 shadow-sm focus:border-[color:color-mix(in_srgb,var(--wb-accent)_40%,#cbd5e1)] focus:outline-none focus:ring-2 focus:ring-[color:color-mix(in_srgb,var(--wb-accent)_30%,transparent)] disabled:cursor-not-allowed disabled:opacity-50 ${
                compact ? 'py-1 pl-2 pr-7 text-[11px]' : 'py-2 pl-2.5 pr-8 text-xs'
            }`}
        >
            {QUICK_TYPE_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                    {o.short}
                </option>
            ))}
        </select>
    )
}

function QuickFieldExampleRow({ example, disabled, compact, onPick }) {
    const [rowType, setRowType] = useState(example.defaultType)
    return (
        <div
            className={`flex flex-col gap-0.5 ${compact ? '' : 'sm:flex-row sm:items-center sm:gap-2'}`}
        >
            <div className="flex flex-wrap items-center gap-1.5">
                <QuickFieldTypeSelect
                    id={`manage-quick-type-${example.label.replace(/\s+/g, '-')}`}
                    value={rowType}
                    onChange={setRowType}
                    disabled={disabled}
                    compact={compact}
                />
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() => onPick(example.label, rowType)}
                    className={`rounded-full border border-slate-200/90 bg-white font-medium text-slate-700 shadow-sm transition hover:border-[color:color-mix(in_srgb,var(--wb-accent)_35%,#e2e8f0)] hover:bg-[color:color-mix(in_srgb,var(--wb-accent)_6%,white)] disabled:cursor-not-allowed disabled:opacity-50 ${
                        compact ? 'px-2.5 py-0.5 text-[11px]' : 'px-2.5 py-1 text-[11px]'
                    }`}
                >
                    {example.label}
                </button>
            </div>
            {example.hint ? (
                <span className={`text-slate-500 ${compact ? 'text-[10px] pl-0.5' : 'text-[11px] sm:pl-0'}`}>
                    {example.hint}
                </span>
            ) : null}
        </div>
    )
}

/** When true, no outer card — use inside {@link ManageFieldsNewFieldSection}. */
function QuickFieldStarter({
    value,
    onChange,
    onSubmit,
    disabled,
    compact,
    showLabel = true,
    embedded = false,
    examples = QUICK_FIELD_EXAMPLES,
    draftFieldType,
    onDraftFieldTypeChange,
    onPickExample,
}) {
    const canSubmit = value.trim().length > 0 && !disabled
    const shellClass = embedded
        ? 'space-y-3'
        : compact
          ? 'rounded-xl border border-[color:color-mix(in_srgb,var(--wb-accent)_20%,#e2e8f0)] bg-[color:color-mix(in_srgb,var(--wb-accent)_5%,white)] p-3 sm:p-4'
          : 'rounded-2xl border border-[color:color-mix(in_srgb,var(--wb-accent)_24%,#e2e8f0)] bg-gradient-to-br from-[color:color-mix(in_srgb,var(--wb-accent)_8%,white)] to-slate-50/80 p-4 shadow-sm sm:p-5'

    return (
        <div className={shellClass}>
            {showLabel && !embedded ? (
                <p className="text-sm font-semibold text-slate-900">New field</p>
            ) : null}
            <p className={`text-xs text-slate-600 ${showLabel && !embedded ? 'mt-0.5' : ''}`}>
                {embedded
                    ? 'Choose list type, name the field, then Create — example starters are below.'
                    : compact
                      ? 'Pick list type, then name — we’ll jump you to answer choices when it’s a list.'
                      : 'Choose single- or multi-select for lists; we’ll highlight where to add options.'}
            </p>
            <div
                className={`flex flex-col gap-2 ${showLabel && !embedded ? 'mt-3' : embedded ? 'mt-2' : 'mt-2'} sm:flex-row sm:items-stretch`}
            >
                <label htmlFor="manage-quick-field-input" className="sr-only">
                    Field name
                </label>
                <input
                    id="manage-quick-field-input"
                    type="text"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault()
                            if (canSubmit) onSubmit()
                        }
                    }}
                    placeholder="e.g. SKU, Department"
                    autoComplete="off"
                    disabled={disabled}
                    className="min-h-[2.75rem] w-full min-w-0 flex-1 rounded-xl border border-slate-200/90 bg-white px-3 py-2 text-sm text-slate-900 shadow-inner placeholder:text-slate-400 focus:border-[color:color-mix(in_srgb,var(--wb-accent)_45%,#cbd5e1)] focus:outline-none focus:ring-2 focus:ring-[color:color-mix(in_srgb,var(--wb-accent)_35%,transparent)] disabled:cursor-not-allowed disabled:opacity-60"
                />
                <div className="flex flex-col gap-2 sm:flex-row sm:items-stretch">
                    <QuickFieldTypeSelect
                        id="manage-quick-draft-type"
                        value={draftFieldType}
                        onChange={onDraftFieldTypeChange}
                        disabled={disabled}
                        compact={compact}
                    />
                    <button
                        type="button"
                        disabled={!canSubmit}
                        onClick={() => {
                            if (canSubmit) onSubmit()
                        }}
                        className={`inline-flex shrink-0 items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-md transition hover:opacity-95 disabled:cursor-not-allowed disabled:opacity-50 ${productButtonPrimary}`}
                    >
                        <PlusIcon className="h-4 w-4 shrink-0" aria-hidden />
                        Create field
                    </button>
                </div>
            </div>
            {examples.length > 0 ? (
                <div className="mt-4 border-t border-[color:color-mix(in_srgb,var(--wb-accent)_12%,#e2e8f0)] pt-3">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Example starters</p>
                    <p className="mt-1 text-xs leading-snug text-slate-600">
                        These rows are <span className="font-medium text-slate-800">illustrations only</span> — they are
                        not saved fields. Click a pill to pre-fill the name and list type, or type your own above.
                    </p>
                    <div className="mt-2 space-y-2" aria-label="Example field name starters">
                        {examples.map((ex) => (
                            <QuickFieldExampleRow
                                key={ex.label}
                                example={ex}
                                disabled={disabled}
                                compact={compact}
                                onPick={onPickExample}
                            />
                        ))}
                    </div>
                </div>
            ) : null}
        </div>
    )
}

const NEW_FIELD_SECTION_PANEL_ID = 'manage-fields-new-field-section-panel'

/** Collapsible quick-create + examples — keeps “On for this folder” above the fold when collapsed. */
function ManageFieldsNewFieldSection({ expanded, onToggle, children }) {
    return (
        <section
            className="overflow-hidden rounded-xl border border-slate-200/90 bg-white shadow-sm"
            aria-label="Create a new custom field"
        >
            <button
                type="button"
                aria-expanded={expanded}
                aria-controls={NEW_FIELD_SECTION_PANEL_ID}
                onClick={onToggle}
                className="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left transition hover:bg-slate-50/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--wb-ring)] focus-visible:ring-offset-2 sm:px-4 sm:py-3"
            >
                <div className="min-w-0">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-[var(--wb-accent)]">
                        New field
                    </p>
                    <p className="text-sm font-semibold text-slate-900">Create a custom field</p>
                    <p className="mt-0.5 text-xs leading-snug text-slate-600">
                        {expanded
                            ? 'Name, list type, Create field, and example starters below — collapse when finished.'
                            : 'Expand for quick create and illustrated example starters (or use Add field in the header).'}
                    </p>
                </div>
                <ChevronDownIcon
                    className={`h-5 w-5 shrink-0 text-slate-500 transition-transform duration-200 ${
                        expanded ? 'rotate-180' : ''
                    }`}
                    aria-hidden
                />
            </button>
            <div
                id={NEW_FIELD_SECTION_PANEL_ID}
                role="region"
                className={
                    expanded ? 'border-t border-slate-200/90 bg-slate-50/50 px-3 py-3 sm:px-4 sm:py-4' : 'hidden'
                }
            >
                {children}
            </div>
        </section>
    )
}

function isAutomatedField(field) {
    return field.population_mode === 'automatic' && field.readonly === true
}

function isFilterOnlyField(field) {
    return (field.key ?? '') === 'dominant_hue_group'
}

/** Effective visibility for chips/coverage: hide invalid filter/primary for ineligible field types (legacy DB flags). */
function resolveEffectiveForDisplay(field, categoryId, data) {
    const base = resolveEffective(field, categoryId, data)
    if (!canUseSidebarFilter(fieldDataType(field))) {
        return { ...base, filter: false, primary: false }
    }
    return base
}

function resolveEffective(field, categoryId, data) {
    const override = categoryId && data?.overrides?.[categoryId]
    const upload =
        override?.show_on_upload !== undefined
            ? override.show_on_upload
            : (field.effective_show_on_upload ?? field.show_on_upload ?? true)
    const edit =
        override?.show_on_edit !== undefined
            ? override.show_on_edit
            : (field.effective_show_on_edit ?? field.show_on_edit ?? true)
    const filter =
        override?.show_in_filters !== undefined
            ? override.show_in_filters
            : (field.effective_show_in_filters ?? field.show_in_filters ?? true)
    let primary = field.is_primary ?? false
    if (categoryId && data?.overrides?.[categoryId]?.is_primary !== undefined && data.overrides[categoryId].is_primary !== null) {
        primary = data.overrides[categoryId].is_primary
    }
    let required = field.is_required ?? false
    if (categoryId && data?.overrides?.[categoryId]?.is_required !== undefined && data.overrides[categoryId].is_required !== null) {
        required = data.overrides[categoryId].is_required
    }
    return { upload, edit, filter, primary, required, aiEligible: field.ai_eligible ?? false }
}

function fieldDataType(field) {
    return String(field.field_type ?? field.type ?? 'text').toLowerCase()
}

function typeBadgeLabel(field) {
    const t = fieldDataType(field)
    const map = {
        text: 'Text',
        textarea: 'Textarea',
        number: 'Number',
        boolean: 'Boolean',
        date: 'Date',
        select: 'Select',
        multiselect: 'Multi-select',
    }
    return map[t] || (t ? `${t.charAt(0).toUpperCase()}${t.slice(1)}` : 'Field')
}

/** One short scan line for where the field shows up (no chip column). */
function appearanceLine(field, effective, isAutomated) {
    const t = fieldDataType(field)
    if (isFilterOnlyField(field)) return 'Shown in filters'
    if (isAutomated) return 'Filled automatically when on'
    if (t === 'date') return 'Date field for this folder'
    if ((t === 'text' || t === 'textarea') && canUseSearch(t)) return 'Found through search'
    if (t === 'number') return 'Number value'
    const u = effective.upload
    const e = effective.edit
    const f = effective.filter
    if (!u && !e && !f) return 'Off for this folder'
    if (u && e && f) return 'Shown on upload, quick view, and filters'
    if (!u && e && !f) return 'Shown in quick view only'
    if (u && !e && !f) return 'Shown on upload only'
    if (!u && !e && f) return 'Shown in filters only'
    if (u && e) return 'Shown on upload and quick view'
    if (u && f) return 'Shown on upload and in filters'
    if (e && f) return 'Shown in quick view and filters'
    return 'Shown for this folder'
}

function FieldValuesPreview({ field, isSystemField }) {
    const t = fieldDataType(field)
    const options = Array.isArray(field.options) ? field.options : []

    if (t === 'boolean') {
        return <span className="text-xs text-slate-600">Yes / No</span>
    }
    if (t === 'date') {
        return <span className="text-xs text-slate-600">Date value</span>
    }
    if (t === 'number') {
        return <span className="text-xs text-slate-600">Number value</span>
    }
    if (t === 'text' || t === 'textarea') {
        return <span className="text-xs text-slate-600">Free text</span>
    }
    if (t === 'select' || t === 'multiselect') {
        if (options.length === 0) {
            if (isSystemField) {
                return <span className="text-xs text-slate-600">Preset options</span>
            }
            return (
                <span className="text-xs font-medium text-amber-800/90">Add values in Configure</span>
            )
        }
        const labels = options
            .map((o) => {
                if (!o) return ''
                const v = o.label ?? o.value
                return v !== undefined && v !== null && String(v) !== '' ? String(v) : ''
            })
            .filter(Boolean)
        const shown = labels.slice(0, 4)
        const rest = labels.length - shown.length
        return (
            <div className="flex min-w-0 flex-nowrap items-center gap-1 overflow-hidden">
                {shown.map((label, i) => (
                    <span
                        key={`${label}-${i}`}
                        className="inline-flex max-w-[7rem] shrink-0 truncate rounded-full border border-[color:color-mix(in_srgb,var(--wb-accent)_22%,#e2e8f0)] bg-[color:color-mix(in_srgb,var(--wb-accent)_6%,#f8fafc)] px-1.5 py-0.5 text-[11px] font-medium text-slate-700"
                        title={label}
                    >
                        {label}
                    </span>
                ))}
                {rest > 0 ? (
                    <span className="shrink-0 text-[11px] font-medium text-slate-500" aria-label={`${rest} more options`}>
                        +{rest}
                    </span>
                ) : null}
            </div>
        )
    }
    return <span className="text-xs text-slate-600">—</span>
}

function FieldListRow({
    field,
    isAutomated,
    isEnabled,
    visibilityLoading,
    selectedCategoryId,
    selectedCategory,
    categoryData,
    canManageVisibility,
    primaryTypeKey,
    onToggle,
    onConfigure,
    switchClass,
    isTenantCreated,
}) {
    const data = categoryData
    const effective = resolveEffectiveForDisplay(field, selectedCategoryId, data)
    const isSystemField = field.scope === 'system' || field.is_system || !isTenantCreated
    const displayName = fieldTitle(field, primaryTypeKey)
    const folderName = selectedCategory?.name ?? 'this folder'
    const line = appearanceLine(field, effective, isAutomated)
    const lockedPreset = isAutomated || !isTenantCreated
    const lockHint = isAutomated
        ? 'This field is filled automatically when enabled.'
        : 'Preset field — some settings are fixed in Configure.'

    return (
        <div
            role="group"
            aria-label={`${displayName}, ${typeBadgeLabel(field)}`}
            className={`rounded-lg border border-slate-200/90 bg-white px-3 py-2 shadow-sm transition-shadow ${
                visibilityLoading ? 'opacity-90' : 'hover:shadow-sm'
            } ${!isEnabled && !visibilityLoading ? 'bg-slate-50/60' : ''}`}
        >
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                <div className="flex min-w-0 flex-1 flex-col gap-1 sm:flex-row sm:items-start sm:gap-4">
                    <div className="flex min-w-0 shrink-0 flex-col gap-1 sm:w-[min(100%,13rem)]">
                        <div className="flex min-w-0 items-center gap-1.5">
                            <span
                                className="min-w-0 truncate text-sm font-semibold text-slate-900"
                                title={displayName}
                            >
                                {displayName}
                            </span>
                            {lockedPreset ? (
                                <span className="inline-flex shrink-0 text-slate-400" title={lockHint}>
                                    <LockClosedIcon className="h-3.5 w-3.5" aria-hidden />
                                    <span className="sr-only">{lockHint}</span>
                                </span>
                            ) : null}
                        </div>
                        {field.key ? (
                            <span
                                className="min-w-0 truncate font-mono text-[11px] text-slate-400"
                                title={field.key}
                            >
                                {field.key}
                            </span>
                        ) : null}
                        <span className="inline-flex w-fit shrink-0 rounded border border-slate-200/90 bg-slate-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-600">
                            {typeBadgeLabel(field)}
                        </span>
                    </div>
                    <div className="min-w-0 flex-1 space-y-0.5">
                        <p className="text-xs text-slate-600">{line}</p>
                        <div className="text-xs leading-tight text-slate-700">
                            <FieldValuesPreview field={field} isSystemField={isSystemField} />
                        </div>
                    </div>
                </div>

                <div className="flex shrink-0 flex-row items-center justify-end gap-2 sm:flex-col sm:items-end sm:justify-center sm:gap-1.5">
                    <div className="flex items-center gap-1.5">
                        <button
                            type="button"
                            role="switch"
                            aria-checked={!visibilityLoading && isEnabled}
                            aria-busy={visibilityLoading}
                            aria-label={`${isEnabled ? 'Disable' : 'Enable'} ${displayName} for ${folderName}`}
                            disabled={!canManageVisibility || !selectedCategoryId || visibilityLoading}
                            onClick={() => onToggle(field.id, selectedCategoryId, !isEnabled)}
                            className={`shrink-0 ${switchClass(!visibilityLoading && isEnabled)} ${
                                visibilityLoading ? 'cursor-wait opacity-60' : ''
                            }`}
                        >
                            <span
                                className={`pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow transition ${
                                    isEnabled && !visibilityLoading ? 'translate-x-4' : 'translate-x-0'
                                }`}
                            />
                        </button>
                        <span className="w-8 text-xs font-medium text-slate-600 tabular-nums" aria-live="polite">
                            {visibilityLoading ? '…' : isEnabled ? 'On' : 'Off'}
                        </span>
                    </div>
                    <button
                        type="button"
                        disabled={visibilityLoading}
                        aria-label={`Configure ${displayName} for ${folderName}`}
                        onClick={() => {
                            void onConfigure(field)
                        }}
                        className="inline-flex min-w-[4.75rem] items-center justify-center rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-800 shadow-sm transition hover:bg-slate-50"
                    >
                        Configure
                    </button>
                </div>
            </div>
        </div>
    )
}

export default function ManageFieldsWorkspace({
    brand,
    categories = [],
    registry,
    fieldFilter: fieldFilterProp = null,
    field_filter = null,
    lowCoverageFieldKeys: lowCoverageKeysProp,
    low_coverage_field_keys,
    canManageVisibility = true,
    canManageBrandCategories = false,
    canManageFields = false,
    customFieldsLimit = null,
    metadataFieldFamilies = {},
    selectedCategoryId = null,
    onSaveNotice,
    hubEmbedded = false,
}) {
    const fieldFilter = fieldFilterProp ?? field_filter ?? null
    const lowCoverageFieldKeys = lowCoverageKeysProp ?? low_coverage_field_keys ?? []

    const { system_fields: systemFields = [], tenant_fields: tenantFields = [] } = registry || {}
    const allFields = useMemo(() => [...systemFields, ...tenantFields], [systemFields, tenantFields])
    const tenantFieldIdSet = useMemo(() => new Set(tenantFields.map((f) => f.id)), [tenantFields])

    const { manageableFields, automatedFields } = useMemo(() => {
        const manageable = []
        const automated = []
        allFields.forEach((field) => {
            if (isAutomatedField(field)) automated.push(field)
            else manageable.push(field)
        })
        return { manageableFields: manageable, automatedFields: automated }
    }, [allFields])

    const typeFamilyFieldKeys = useMemo(() => {
        const tf = metadataFieldFamilies?.type
        if (!tf || !Array.isArray(tf.fields)) return []
        return tf.fields
    }, [metadataFieldFamilies])

    const [fieldCategoryData, setFieldCategoryData] = useState({})
    const [modalOpen, setModalOpen] = useState(false)
    const [editingField, setEditingField] = useState(null)
    const [successMessage, setSuccessMessage] = useState(null)
    const [confirmDisableCoreOpen, setConfirmDisableCoreOpen] = useState(false)
    const [pendingDisable, setPendingDisable] = useState(null)
    const [ebiToggleLoading, setEbiToggleLoading] = useState(false)
    const [aiLibRefToggleLoading, setAiLibRefToggleLoading] = useState(false)
    const [quickFieldName, setQuickFieldName] = useState('')
    const [quickFieldDraftType, setQuickFieldDraftType] = useState('select')
    const [newFieldSectionExpanded, setNewFieldSectionExpanded] = useState(false)
    const [createFieldPrefill, setCreateFieldPrefill] = useState(null)

    const noticeTimerRef = useRef(null)
    useEffect(() => {
        return () => {
            if (noticeTimerRef.current) {
                clearTimeout(noticeTimerRef.current)
                noticeTimerRef.current = null
            }
        }
    }, [])

    const postNotice = useCallback(
        (text, variant = 'success', durationMs) => {
            const dur = durationMs ?? (variant === 'error' ? 6000 : 4000)
            if (typeof onSaveNotice === 'function') {
                onSaveNotice(text, { variant, durationMs: dur })
                return
            }
            setSuccessMessage(text)
            if (noticeTimerRef.current) clearTimeout(noticeTimerRef.current)
            noticeTimerRef.current = setTimeout(() => {
                setSuccessMessage(null)
                noticeTimerRef.current = null
            }, dur)
        },
        [onSaveNotice]
    )

    const fieldCategoryDataRef = useRef(fieldCategoryData)
    useEffect(() => {
        fieldCategoryDataRef.current = fieldCategoryData
    }, [fieldCategoryData])

    const selectedCategory = useMemo(
        () => categories.find((c) => c.id === selectedCategoryId) ?? null,
        [categories, selectedCategoryId]
    )

    const canToggleEbi =
        (canManageVisibility || canManageBrandCategories) && !!brand?.id && !!selectedCategory

    const toggleEbiEnabled = useCallback(async () => {
        if (!canToggleEbi || !selectedCategory) return
        const brandId = brand.id
        const current = selectedCategory.ebi_enabled === true
        const newValue = !current
        setEbiToggleLoading(true)
        try {
            const url =
                typeof route === 'function'
                    ? route('brands.categories.ebi-enabled', { brand: brandId, category: selectedCategory.id })
                    : `/app/api/brands/${brandId}/categories/${selectedCategory.id}/ebi-enabled`
            const response = await fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ ebi_enabled: newValue }),
            })
            if (response.ok) {
                postNotice(`Brand Intelligence ${newValue ? 'enabled' : 'disabled'} for this folder.`)
                router.reload({ only: ['categories'] })
            } else {
                const errorData = await response.json().catch(() => ({}))
                postNotice(
                    errorData.message || errorData.error || 'Failed to update Brand Intelligence setting.',
                    'error',
                    4000
                )
            }
        } catch (error) {
            console.error('Failed to toggle ebi_enabled:', error)
            postNotice('Failed to update Brand Intelligence setting.', 'error')
        } finally {
            setEbiToggleLoading(false)
        }
    }, [brand?.id, canToggleEbi, selectedCategory, postNotice])

    const canToggleAiLibRef =
        (canManageVisibility || canManageBrandCategories) &&
        !!brand?.id &&
        !!selectedCategory &&
        !selectedCategory.is_system

    const toggleAiLibraryReferences = useCallback(async () => {
        if (!canToggleAiLibRef || !selectedCategory) return
        const brandId = brand.id
        const current = selectedCategory.ai_use_library_references === true
        const newValue = !current
        setAiLibRefToggleLoading(true)
        try {
            const url =
                typeof route === 'function'
                    ? route('brands.categories.ai-library-references', { brand: brandId, category: selectedCategory.id })
                    : `/app/api/brands/${brandId}/categories/${selectedCategory.id}/ai-library-references`
            const response = await fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ ai_use_library_references: newValue }),
            })
            if (response.ok) {
                postNotice(
                    newValue
                        ? 'AI library reference hints enabled for this custom folder.'
                        : 'AI library reference hints off for this folder.'
                )
                router.reload({ only: ['categories'] })
            } else {
                const errorData = await response.json().catch(() => ({}))
                postNotice(
                    errorData.message || errorData.error || 'Failed to update AI library reference setting.',
                    'error',
                    4000
                )
            }
        } catch (error) {
            console.error('Failed to toggle ai_use_library_references:', error)
            postNotice('Failed to update AI library reference setting.', 'error')
        } finally {
            setAiLibRefToggleLoading(false)
        }
    }, [brand?.id, canToggleAiLibRef, selectedCategory, postNotice])

    const loadFieldCategoryData = useCallback(async (field, forceRefetch = false) => {
        if (!forceRefetch && fieldCategoryDataRef.current[field.id]) {
            return fieldCategoryDataRef.current[field.id]
        }
        try {
            const response = await fetch(`/app/api/tenant/metadata/fields/${field.id}/categories`)
            const data = await response.json()
            const suppressedIds = data.suppressed_category_ids || []
            const categoryOverrides = data.category_overrides || {}
            const categoryData = {
                suppressed: suppressedIds,
                visible: categories.filter((cat) => !suppressedIds.includes(cat.id)).map((cat) => cat.id),
                overrides: categoryOverrides,
            }
            setFieldCategoryData((prev) => ({
                ...prev,
                [field.id]: categoryData,
            }))
            return categoryData
        } catch (e) {
            console.error('Failed to load field category data:', e)
            const fallback = {
                suppressed: [],
                visible: categories.map((c) => c.id),
                overrides: {},
                __loadError: true,
            }
            setFieldCategoryData((prev) => ({
                ...prev,
                [field.id]: fallback,
            }))
            return fallback
        }
    }, [categories])

    useEffect(() => {
        if (!selectedCategoryId) return
        ;[...manageableFields, ...automatedFields].forEach((field) => {
            loadFieldCategoryData(field)
        })
        // Lengths (not array identity) avoid refetch loops when Inertia passes new array references.
        // eslint-disable-next-line react-hooks/exhaustive-deps -- use current field lists when lengths or category change
    }, [selectedCategoryId, manageableFields.length, automatedFields.length, loadFieldCategoryData])

    const eq = (a, b) => String(a) === String(b)

    const getFieldsForCategory = useMemo(() => {
        if (!selectedCategoryId) {
            return {
                enabled: [],
                available: [],
                pending: [],
                enabledAutomated: [],
                availableAutomated: [],
                pendingAutomated: [],
            }
        }
        const enabled = []
        const available = []
        const pending = []
        const enabledAutomated = []
        const availableAutomated = []
        const pendingAutomated = []
        manageableFields.forEach((field) => {
            const categoryData = fieldCategoryData[field.id]
            if (categoryData === undefined) {
                pending.push(field)
                return
            }
            if (categoryData.__loadError) {
                available.push(field)
                return
            }
            const isEnabled = !(categoryData.suppressed || []).some((sid) => eq(sid, selectedCategoryId))
            if (isEnabled) enabled.push(field)
            else available.push(field)
        })
        automatedFields.forEach((field) => {
            const categoryData = fieldCategoryData[field.id]
            if (categoryData === undefined) {
                pendingAutomated.push(field)
                return
            }
            if (categoryData.__loadError) {
                availableAutomated.push(field)
                return
            }
            const isEnabled = !(categoryData.suppressed || []).some((sid) => eq(sid, selectedCategoryId))
            if (isEnabled) enabledAutomated.push(field)
            else availableAutomated.push(field)
        })
        return {
            enabled,
            available,
            pending,
            enabledAutomated,
            availableAutomated,
            pendingAutomated,
        }
    }, [selectedCategoryId, manageableFields, automatedFields, fieldCategoryData])

    const isFieldRegistryLoading = useMemo(() => {
        if (!selectedCategoryId) return false
        const all = [...manageableFields, ...automatedFields]
        if (all.length === 0) return false
        return all.some((f) => fieldCategoryData[f.id] === undefined)
    }, [selectedCategoryId, manageableFields, automatedFields, fieldCategoryData])

    const tableRows = useMemo(() => {
        if (!selectedCategoryId || !selectedCategory) return []
        const resolvedTypeKey = selectedCategory.type_field?.field_key ?? null
        const hideTypeFamilyMember = (field) => {
            if (!typeFamilyFieldKeys.length || !typeFamilyFieldKeys.includes(field.key)) return false
            if (!resolvedTypeKey) return true
            return field.key !== resolvedTypeKey
        }
        const {
            enabled,
            available,
            pending,
            enabledAutomated,
            availableAutomated,
            pendingAutomated,
        } = getFieldsForCategory

        const findFieldByKey = (key) =>
            enabled.find((f) => f.key === key) ||
            available.find((f) => f.key === key) ||
            pending.find((f) => f.key === key) ||
            null

        const isPending = (field, automated) => {
            if (!field) return false
            return automated
                ? pendingAutomated.some((f) => f.id === field.id)
                : pending.some((f) => f.id === field.id)
        }

        const primaryTypeField = resolvedTypeKey ? findFieldByKey(resolvedTypeKey) : null
        const descriptorKeys = ['environment_type', 'subject_type']
        const primaryDescriptorFields = descriptorKeys
            .map((key) => findFieldByKey(key))
            .filter(Boolean)

        const otherFilter = (f) =>
            !hideTypeFamilyMember(f) && !descriptorKeys.includes(f.key) && !(resolvedTypeKey && f.key === resolvedTypeKey)
        const otherEnabled = enabled.filter(otherFilter)
        const otherAvailable = available.filter(otherFilter)
        const otherPending = pending.filter(otherFilter)

        const rows = []
        const pushRow = (field, { automated, enabled: en, visibilityLoading = false }) => {
            rows.push({
                field,
                isAutomated: automated,
                isEnabled: en,
                visibilityLoading,
                key: `${field.id}-${automated ? 'a' : 'm'}`,
            })
        }

        if (primaryTypeField) {
            const loading = isPending(primaryTypeField, false)
            pushRow(primaryTypeField, {
                automated: false,
                enabled: !loading && enabled.some((f) => f.id === primaryTypeField.id),
                visibilityLoading: loading,
            })
        }
        primaryDescriptorFields.forEach((field) => {
            const loading = isPending(field, false)
            pushRow(field, {
                automated: false,
                enabled: !loading && enabled.some((f) => f.id === field.id),
                visibilityLoading: loading,
            })
        })
        otherEnabled.forEach((f) => pushRow(f, { automated: false, enabled: true, visibilityLoading: false }))
        otherAvailable.forEach((f) => pushRow(f, { automated: false, enabled: false, visibilityLoading: false }))
        otherPending.forEach((f) => pushRow(f, { automated: false, enabled: false, visibilityLoading: true }))
        enabledAutomated.forEach((f) => pushRow(f, { automated: true, enabled: true, visibilityLoading: false }))
        availableAutomated.forEach((f) => pushRow(f, { automated: true, enabled: false, visibilityLoading: false }))
        pendingAutomated.forEach((f) => pushRow(f, { automated: true, enabled: false, visibilityLoading: true }))

        return rows
    }, [selectedCategoryId, selectedCategory, getFieldsForCategory, typeFamilyFieldKeys])

    const rowsAfterFilter = useMemo(() => {
        let rows = tableRows
        if (fieldFilter === 'low_coverage' && lowCoverageFieldKeys?.length) {
            const set = new Set(lowCoverageFieldKeys.map(String))
            rows = rows.filter((r) => set.has(r.field.key))
        }
        return rows
    }, [tableRows, fieldFilter, lowCoverageFieldKeys])

    const primaryTypeKeyForSort = selectedCategory?.type_field?.field_key ?? null

    /** Enabled custom + system, single list (sorted by name). */
    const onRows = useMemo(() => {
        const list = rowsAfterFilter.filter((r) => r.isEnabled && !r.visibilityLoading)
        return sortRowsByFieldName(list, primaryTypeKeyForSort)
    }, [rowsAfterFilter, primaryTypeKeyForSort])

    /** Custom / tenant fields that are off or still loading visibility. */
    const availableCustomRows = useMemo(() => {
        const list = rowsAfterFilter.filter(
            (r) => tenantFieldIdSet.has(r.field.id) && (!r.isEnabled || r.visibilityLoading)
        )
        return sortRowsByFieldName(list, primaryTypeKeyForSort)
    }, [rowsAfterFilter, tenantFieldIdSet, primaryTypeKeyForSort])

    /** Built-in fields that are off or loading — lives in the bottom collapsible. */
    const offSystemRows = useMemo(() => {
        const list = rowsAfterFilter.filter(
            (r) => !tenantFieldIdSet.has(r.field.id) && (!r.isEnabled || r.visibilityLoading)
        )
        return sortRowsByFieldName(list, primaryTypeKeyForSort)
    }, [rowsAfterFilter, tenantFieldIdSet, primaryTypeKeyForSort])

    const hasAnyCustomFieldDefinitions = useMemo(
        () => rowsAfterFilter.some((r) => tenantFieldIdSet.has(r.field.id)),
        [rowsAfterFilter, tenantFieldIdSet]
    )

    const hasSystemRowsInView = useMemo(
        () => rowsAfterFilter.some((r) => !tenantFieldIdSet.has(r.field.id)),
        [rowsAfterFilter, tenantFieldIdSet]
    )

    const quickFieldExamplesFiltered = useMemo(
        () =>
            QUICK_FIELD_EXAMPLES.filter(
                (ex) => !quickSuggestionIsTaken(ex.label, rowsAfterFilter, primaryTypeKeyForSort),
            ),
        [rowsAfterFilter, primaryTypeKeyForSort]
    )

    const onPanelId = useId()
    const availPanelId = useId()
    const builtinPanelId = useId()

    useEffect(() => {
        setQuickFieldName('')
        setQuickFieldDraftType('select')
    }, [selectedCategoryId])

    /** Collapse quick-create when this folder already has field rows (list first); expand when empty so first field is obvious. */
    useEffect(() => {
        if (!selectedCategoryId || isFieldRegistryLoading) return
        setNewFieldSectionExpanded(rowsAfterFilter.length === 0 && canManageFields)
    }, [selectedCategoryId, isFieldRegistryLoading, rowsAfterFilter.length, canManageFields])

    const showAllFieldsOffBanner = useMemo(() => {
        if (!selectedCategoryId || isFieldRegistryLoading || fieldFilter === 'low_coverage') return false
        if (rowsAfterFilter.length === 0) return false
        if (rowsAfterFilter.some((r) => r.visibilityLoading)) return false
        return rowsAfterFilter.every((r) => !r.isEnabled)
    }, [selectedCategoryId, isFieldRegistryLoading, fieldFilter, rowsAfterFilter])

    const clearLowCoverageHref = useMemo(() => {
        if (typeof route !== 'function') return MANAGE_CATEGORIES_URL
        const slug = selectedCategory?.slug
        return slug ? route('manage.categories', { category: slug }) : route('manage.categories')
    }, [selectedCategory])

    const toggleCategoryField = useCallback(
        async (fieldId, categoryId, isSuppressed, meta = {}) => {
            const willBeEnabled = !!isSuppressed
            setFieldCategoryData((prev) => {
                const current = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
                const newSuppressed = willBeEnabled
                    ? (current.suppressed || []).filter((id) => !eq(id, categoryId))
                    : [...(current.suppressed || []), categoryId]
                const visibleCategories = categories.filter((cat) => !newSuppressed.some((sid) => eq(sid, cat.id)))
                return {
                    ...prev,
                    [fieldId]: {
                        ...current,
                        suppressed: newSuppressed,
                        visible: visibleCategories.map((cat) => cat.id),
                    },
                }
            })

            let succeeded = false
            try {
                const response = await fetch(
                    `/app/api/tenant/metadata/fields/${fieldId}/categories/${categoryId}/visibility`,
                    {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': getCsrfToken(),
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ is_hidden: !willBeEnabled }),
                    }
                )
                if (!response.ok) {
                    setFieldCategoryData((prev) => {
                        const current = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
                        const reverted = willBeEnabled
                            ? [...(current.suppressed || []), categoryId]
                            : (current.suppressed || []).filter((id) => !eq(id, categoryId))
                        const visibleCategories = categories.filter((cat) => !reverted.some((sid) => eq(sid, cat.id)))
                        return {
                            ...prev,
                            [fieldId]: {
                                ...current,
                                suppressed: reverted,
                                visible: visibleCategories.map((cat) => cat.id),
                            },
                        }
                    })
                } else {
                    succeeded = true
                }
            } catch (e) {
                console.error(e)
                setFieldCategoryData((prev) => {
                    const current = prev[fieldId] || { suppressed: [], visible: [], overrides: {} }
                    const reverted = willBeEnabled
                        ? [...(current.suppressed || []), categoryId]
                        : (current.suppressed || []).filter((id) => !eq(id, categoryId))
                    const visibleCategories = categories.filter((cat) => !reverted.some((sid) => eq(sid, cat.id)))
                    return {
                        ...prev,
                        [fieldId]: {
                            ...current,
                            suppressed: reverted,
                            visible: visibleCategories.map((cat) => cat.id),
                        },
                    }
                })
            }
            if (succeeded && meta.fieldLabel && meta.categoryName) {
                postNotice(`${meta.fieldLabel} ${willBeEnabled ? 'enabled' : 'disabled'} for ${meta.categoryName}.`)
            }
        },
        [categories, postNotice]
    )

    const handleToggleWithConfirm = useCallback(
        (fieldId, categoryId, isSuppressed, field, categoryName) => {
            const fieldLabel = field?.label || field?.system_label || field?.key || 'Field'
            const name = categoryName ?? categories.find((c) => String(c.id) === String(categoryId))?.name ?? 'this folder'
            if (!isSuppressed && field && CORE_FIELD_KEYS.includes(field.key)) {
                setPendingDisable({ fieldId, categoryId, fieldLabel, categoryName: name })
                setConfirmDisableCoreOpen(true)
            } else {
                toggleCategoryField(fieldId, categoryId, isSuppressed, { fieldLabel, categoryName: name })
            }
        },
        [toggleCategoryField, categories]
    )

    const wrapToggle = useCallback(
        (field, categoryId) => (fieldId, categoryIdArg, isSuppressed) => {
            const catName =
                categories.find((c) => String(c.id) === String(categoryIdArg ?? categoryId))?.name ?? 'this folder'
            handleToggleWithConfirm(fieldId, categoryIdArg ?? categoryId, isSuppressed, field, catName)
        },
        [handleToggleWithConfirm, categories]
    )

    const openDefinitionModal = async (field) => {
        setCreateFieldPrefill(null)
        try {
            const isCustom = !systemFields.some((sf) => sf.id === field.id)
            const response = await fetch(`/app/tenant/metadata/fields/${field.id}`)
            const data = await response.json()
            if (data.field) {
                setEditingField(
                    isCustom
                        ? data.field
                        : {
                              ...field,
                              ...data.field,
                              scope: 'system',
                              is_system: true,
                              ai_eligible:
                                  data.field?.ai_eligible !== undefined
                                      ? data.field.ai_eligible
                                      : (field.ai_eligible ?? false),
                          }
                )
                setModalOpen(true)
            }
        } catch (e) {
            console.error(e)
        }
    }

    const handleModalSuccess = () => {
        setModalOpen(false)
        setEditingField(null)
        setCreateFieldPrefill(null)
        setFieldCategoryData({})
        router.reload({ only: ['registry', 'customFieldsLimit'] })
    }

    const openBlankCreateModal = useCallback(() => {
        setCreateFieldPrefill(null)
        setEditingField(null)
        setModalOpen(true)
    }, [])

    /** Folder schema flyout: `?new_field=1` expands the quick “new field” strip (or opens the create modal if that strip is not shown). */
    useEffect(() => {
        if (typeof window === 'undefined') return
        if (!selectedCategoryId || !canManageFields) return
        const params = new URLSearchParams(window.location.search)
        if (params.get('new_field') !== '1') return

        setNewFieldSectionExpanded(true)
        params.delete('new_field')
        const qs = params.toString()
        const nextUrl = `${window.location.pathname}${qs ? `?${qs}` : ''}${window.location.hash || ''}`
        window.history.replaceState(window.history.state, '', nextUrl)

        const t = window.setTimeout(() => {
            const el = document.getElementById(NEW_FIELD_SECTION_PANEL_ID)
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
            } else {
                openBlankCreateModal()
            }
        }, 120)
        return () => clearTimeout(t)
    }, [selectedCategoryId, canManageFields, openBlankCreateModal])

    const openQuickCreateModal = useCallback(() => {
        const name = quickFieldName.trim()
        if (!name) return
        if (customFieldsLimit && !customFieldsLimit.can_create) return
        const ft = quickFieldDraftType === 'multiselect' ? 'multiselect' : 'select'
        setCreateFieldPrefill({
            systemLabel: name,
            fieldType: ft,
            highlightValues: true,
        })
        setEditingField(null)
        setModalOpen(true)
    }, [quickFieldName, quickFieldDraftType, customFieldsLimit])

    const onQuickPickExample = useCallback((label, fieldType) => {
        setQuickFieldName(label)
        setQuickFieldDraftType(fieldType === 'multiselect' ? 'multiselect' : 'select')
    }, [])

    const focusQuickFieldInput = useCallback(() => {
        if (typeof document === 'undefined') return
        document.getElementById('manage-quick-field-input')?.focus()
    }, [])

    const switchClass = (on) =>
        `relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--wb-ring)] focus-visible:ring-offset-2 ${
            on ? 'bg-[var(--wb-accent)]' : 'bg-slate-200'
        }`

    return (
        <div
            className={
                hubEmbedded
                    ? 'flex min-h-0 w-full min-w-0 flex-1 flex-col'
                    : 'space-y-6'
            }
            aria-busy={isFieldRegistryLoading || undefined}
        >
            {successMessage && !onSaveNotice && (
                <div className="rounded-lg border border-[color:color-mix(in_srgb,var(--wb-accent)_28%,#e2e8f0)] bg-[color:color-mix(in_srgb,var(--wb-accent)_8%,white)] px-3 py-2 text-sm text-slate-900">
                    {successMessage}
                </div>
            )}

            {fieldFilter === 'low_coverage' && (
                <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-950">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p>
                            Low-coverage fields from Insights
                            {lowCoverageFieldKeys?.length
                                ? ` (${lowCoverageFieldKeys.length})`
                                : ''}
                            . Try another folder if needed.
                        </p>
                        <Link
                            href={clearLowCoverageHref}
                            className="shrink-0 text-sm font-medium text-[var(--wb-link)] hover:opacity-90"
                        >
                            Clear filter
                        </Link>
                    </div>
                </div>
            )}

            <div
                className={`relative overflow-hidden bg-white ${
                    hubEmbedded
                        ? 'rounded-none border-0 shadow-none'
                        : 'rounded-xl border border-slate-200/90 shadow-sm'
                }`}
            >
                {isFieldRegistryLoading && selectedCategoryId ? (
                    <div
                        className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-white/85 px-4 py-10 backdrop-blur-[1px] sm:py-14"
                        role="status"
                    >
                        <ArrowPathIcon className="h-7 w-7 shrink-0 animate-spin text-[var(--wb-accent)]" aria-hidden />
                        <p className="text-sm font-medium text-slate-600">Loading field settings…</p>
                    </div>
                ) : null}

                {!selectedCategoryId ? (
                    <div
                        className="flex flex-col px-4 py-8 sm:px-8 sm:py-12"
                        role="region"
                        aria-labelledby="manage-fields-no-folder-title"
                        tabIndex={0}
                    >
                        <div className="mx-auto w-full max-w-md text-center">
                            <div
                                className="mx-auto flex h-11 w-11 items-center justify-center rounded-xl border border-[color:color-mix(in_srgb,var(--wb-accent)_22%,#e2e8f0)] bg-[color:color-mix(in_srgb,var(--wb-accent)_8%,white)] text-[var(--wb-accent)]"
                                aria-hidden
                            >
                                <FolderIcon className="h-6 w-6" />
                            </div>
                            <h3
                                id="manage-fields-no-folder-title"
                                className="mt-4 text-lg font-semibold tracking-tight text-slate-900"
                            >
                                {categories.length === 0 ? 'Add folders to get started' : 'Choose a folder'}
                            </h3>
                            <p className="mt-2 text-sm leading-relaxed text-slate-600">
                                {categories.length === 0
                                    ? 'Create or add folders for this brand, then open one here to manage fields.'
                                    : 'Manage the fields used for assets in that folder.'}
                            </p>
                        </div>
                    </div>
                ) : (
                    <>
                        {selectedCategory ? (
                            <div className="border-b border-slate-200/90 px-4 pb-4 pt-4 sm:px-5 sm:pb-5 sm:pt-5">
                                <ManageFieldsQuickGuide />
                            </div>
                        ) : null}
                        <header
                            className="border-b border-slate-200/90 border-l-4 bg-[color:color-mix(in_srgb,var(--wb-accent)_7%,white)] px-4 py-3 sm:px-5 sm:py-4"
                            style={{ borderLeftColor: 'var(--wb-accent)' }}
                        >
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                                <div className="min-w-0 flex-1">
                                    <h3 className="text-lg font-semibold tracking-tight text-slate-900 sm:text-xl">
                                        {selectedCategory ? `Fields for ${selectedCategory.name}` : 'Folders & fields'}
                                    </h3>
                                    {selectedCategory ? (
                                        <p className="mt-0.5 max-w-xl text-sm text-slate-600">
                                            Manage the fields used for assets in this folder.
                                        </p>
                                    ) : null}
                                </div>
                                {canManageFields && selectedCategory ? (
                                    <button
                                        type="button"
                                        onClick={openBlankCreateModal}
                                        disabled={customFieldsLimit && !customFieldsLimit.can_create}
                                        title="Add field"
                                        aria-label={`Add field to ${selectedCategory.name}`}
                                        className={`inline-flex max-w-full shrink-0 items-center gap-2 ${productButtonPrimary} px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50`}
                                    >
                                        <PlusIcon className="h-4 w-4 shrink-0" aria-hidden />
                                        Add field
                                    </button>
                                ) : null}
                            </div>
                        </header>

                        <div className="space-y-5 px-4 py-5 sm:px-5 sm:py-6">
                            {canManageFields &&
                            selectedCategoryId &&
                            !isFieldRegistryLoading &&
                            rowsAfterFilter.length > 0 ? (
                                <ManageFieldsNewFieldSection
                                    expanded={newFieldSectionExpanded}
                                    onToggle={() => setNewFieldSectionExpanded((v) => !v)}
                                >
                                    <QuickFieldStarter
                                        embedded
                                        showLabel={false}
                                        value={quickFieldName}
                                        onChange={setQuickFieldName}
                                        onSubmit={openQuickCreateModal}
                                        disabled={Boolean(customFieldsLimit && !customFieldsLimit.can_create)}
                                        compact={false}
                                        examples={quickFieldExamplesFiltered}
                                        draftFieldType={quickFieldDraftType}
                                        onDraftFieldTypeChange={setQuickFieldDraftType}
                                        onPickExample={onQuickPickExample}
                                    />
                                </ManageFieldsNewFieldSection>
                            ) : null}

                            {selectedCategory && (canToggleEbi || canToggleAiLibRef) ? (
                                <div className="rounded-lg border border-slate-200/90 bg-slate-50/80 px-3 py-3 sm:px-4">
                                    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                        Folder options
                                    </p>
                                    {canToggleEbi ? (
                                        <div className="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-semibold text-slate-900">Brand Intelligence</p>
                                                <p className="mt-0.5 text-xs leading-snug text-slate-600">
                                                    Score assets in this folder against your brand (e.g. photography,
                                                    video, campaigns).
                                                </p>
                                            </div>
                                            <button
                                                type="button"
                                                role="switch"
                                                aria-checked={selectedCategory.ebi_enabled === true}
                                                aria-label={`Brand Intelligence for ${selectedCategory.name}: ${selectedCategory.ebi_enabled === true ? 'enabled' : 'disabled'}. Toggle to change.`}
                                                disabled={ebiToggleLoading}
                                                onClick={toggleEbiEnabled}
                                                className={`relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full border-2 border-slate-300/80 transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--wb-ring)] focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 ${
                                                    selectedCategory.ebi_enabled === true
                                                        ? 'bg-[var(--wb-accent)]'
                                                        : 'bg-slate-200'
                                                }`}
                                            >
                                                <span
                                                    className={`pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow transition duration-200 ease-in-out ${
                                                        selectedCategory.ebi_enabled === true
                                                            ? 'translate-x-4'
                                                            : 'translate-x-0'
                                                    }`}
                                                />
                                            </button>
                                        </div>
                                    ) : null}
                                    {canToggleAiLibRef ? (
                                        <div
                                            className={`flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3 ${
                                                canToggleEbi ? 'mt-3 border-t border-slate-200/80 pt-3' : 'mt-2'
                                            }`}
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-semibold text-slate-900">AI library context</p>
                                                <p className="mt-0.5 text-xs leading-snug text-slate-600">
                                                    Optional hints for vision tagging using this folder.
                                                </p>
                                            </div>
                                            <button
                                                type="button"
                                                role="switch"
                                                aria-checked={selectedCategory.ai_use_library_references === true}
                                                aria-label={`AI library context for ${selectedCategory.name}: ${selectedCategory.ai_use_library_references === true ? 'enabled' : 'disabled'}. Toggle to change.`}
                                                disabled={aiLibRefToggleLoading}
                                                onClick={toggleAiLibraryReferences}
                                                className={`relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full border-2 border-slate-300/80 transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--wb-ring)] focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 ${
                                                    selectedCategory.ai_use_library_references === true
                                                        ? 'bg-[var(--wb-accent)]'
                                                        : 'bg-slate-200'
                                                }`}
                                            >
                                                <span
                                                    className={`pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                                        selectedCategory.ai_use_library_references === true
                                                            ? 'translate-x-4'
                                                            : 'translate-x-0'
                                                    }`}
                                                />
                                            </button>
                                        </div>
                                    ) : null}
                                </div>
                            ) : null}

                            <div
                                className="field-rows space-y-4"
                                role="region"
                                aria-labelledby="manage-fields-on-heading"
                            >
                                <h4 id="manage-fields-on-heading" className="sr-only">
                                    Fields for this folder
                                </h4>

                                {showAllFieldsOffBanner && selectedCategory ? (
                                    <div className="rounded-lg border border-amber-200/90 bg-amber-50/60 px-3 py-3 text-sm text-amber-950">
                                        <p className="font-medium">All fields are off</p>
                                        <p className="mt-0.5 text-xs text-amber-900/90">
                                            Turn on the fields you want for uploads, quick view, and filters.
                                        </p>
                                    </div>
                                ) : null}

                                {rowsAfterFilter.length === 0 ? (
                                    <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50/50 px-4 py-8 text-center sm:px-6">
                                        {fieldFilter === 'low_coverage' && tableRows.length > 0 ? (
                                            <p className="text-sm text-slate-600">
                                                No matches in this folder.{' '}
                                                <Link
                                                    href={clearLowCoverageHref}
                                                    className="font-medium text-[var(--wb-link)] hover:opacity-90"
                                                >
                                                    Clear filter
                                                </Link>
                                            </p>
                                        ) : categories.length === 0 ? (
                                            <p className="text-sm text-slate-600">No folders for this brand.</p>
                                        ) : isFieldRegistryLoading ? (
                                            <p className="text-sm text-slate-600">Loading fields…</p>
                                        ) : canManageFields && selectedCategory ? (
                                            <div className="mx-auto max-w-lg text-left">
                                                <QuickFieldStarter
                                                    value={quickFieldName}
                                                    onChange={setQuickFieldName}
                                                    onSubmit={openQuickCreateModal}
                                                    disabled={Boolean(customFieldsLimit && !customFieldsLimit.can_create)}
                                                    compact
                                                    examples={quickFieldExamplesFiltered}
                                                    draftFieldType={quickFieldDraftType}
                                                    onDraftFieldTypeChange={setQuickFieldDraftType}
                                                    onPickExample={onQuickPickExample}
                                                />
                                            </div>
                                        ) : (
                                            <>
                                                <h4 className="text-base font-semibold text-slate-900">No fields yet</h4>
                                                <p className="mt-1 text-sm text-slate-600">
                                                    Add a field for assets in this folder.
                                                </p>
                                                <ul
                                                    className="mt-4 flex flex-wrap justify-center gap-2"
                                                    aria-label="Example fields"
                                                >
                                                    {quickFieldExamplesFiltered.map((ex) => (
                                                        <li
                                                            key={ex.label}
                                                            className="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-700"
                                                        >
                                                            {ex.label}
                                                        </li>
                                                    ))}
                                                </ul>
                                            </>
                                        )}
                                    </div>
                                ) : (
                                    <>
                                        <FieldAccordion
                                            key={`manage-on-${selectedCategoryId}`}
                                            panelId={onPanelId}
                                            title="On for this folder"
                                            count={onRows.length}
                                            defaultExpanded
                                            subtle={false}
                                        >
                                            {onRows.length > 0 ? (
                                                <ul className="space-y-2" aria-label="Fields enabled for this folder">
                                                    {onRows.map((row) => (
                                                        <li key={row.key} id={`manage-field-row-${row.key}`}>
                                                            <FieldListRow
                                                                field={row.field}
                                                                isAutomated={row.isAutomated}
                                                                isEnabled={row.isEnabled}
                                                                visibilityLoading={row.visibilityLoading}
                                                                selectedCategoryId={selectedCategoryId}
                                                                selectedCategory={selectedCategory}
                                                                categoryData={fieldCategoryData[row.field.id]}
                                                                canManageVisibility={canManageVisibility}
                                                                primaryTypeKey={
                                                                    selectedCategory?.type_field?.field_key ?? null
                                                                }
                                                                onToggle={wrapToggle(row.field, selectedCategoryId)}
                                                                onConfigure={openDefinitionModal}
                                                                switchClass={switchClass}
                                                                isTenantCreated={tenantFieldIdSet.has(row.field.id)}
                                                            />
                                                        </li>
                                                    ))}
                                                </ul>
                                            ) : (
                                                <p className="py-1 text-sm text-slate-500">
                                                    Nothing on yet — turn on fields in Available or Built-in.
                                                </p>
                                            )}
                                        </FieldAccordion>

                                        <FieldAccordion
                                            key={`manage-avail-${selectedCategoryId}`}
                                            panelId={availPanelId}
                                            title="Available"
                                            count={availableCustomRows.length}
                                            defaultExpanded={
                                                onRows.length === 0 &&
                                                (availableCustomRows.length > 0 || !hasAnyCustomFieldDefinitions)
                                            }
                                            subtle
                                        >
                                            {availableCustomRows.length > 0 ? (
                                                <ul className="space-y-2" aria-label="Custom fields not enabled">
                                                    {availableCustomRows.map((row) => (
                                                        <li key={row.key} id={`manage-field-row-${row.key}`}>
                                                            <FieldListRow
                                                                field={row.field}
                                                                isAutomated={row.isAutomated}
                                                                isEnabled={row.isEnabled}
                                                                visibilityLoading={row.visibilityLoading}
                                                                selectedCategoryId={selectedCategoryId}
                                                                selectedCategory={selectedCategory}
                                                                categoryData={fieldCategoryData[row.field.id]}
                                                                canManageVisibility={canManageVisibility}
                                                                primaryTypeKey={
                                                                    selectedCategory?.type_field?.field_key ?? null
                                                                }
                                                                onToggle={wrapToggle(row.field, selectedCategoryId)}
                                                                onConfigure={openDefinitionModal}
                                                                switchClass={switchClass}
                                                                isTenantCreated={tenantFieldIdSet.has(row.field.id)}
                                                            />
                                                        </li>
                                                    ))}
                                                </ul>
                                            ) : hasAnyCustomFieldDefinitions ? (
                                                <p className="text-sm text-slate-600">All custom fields are on.</p>
                                            ) : (
                                                <div className="rounded-lg border border-dashed border-slate-200/90 bg-slate-50/50 px-3 py-3 sm:px-4">
                                                    <p className="text-sm text-slate-600">
                                                        Expand <span className="font-medium text-slate-800">Create a custom field</span>{' '}
                                                        at the top (above the lists), then pick a starter or type a name.
                                                    </p>
                                                    <div className="mt-2 flex flex-col gap-2">
                                                        {quickFieldExamplesFiltered.map((ex) => (
                                                            <QuickFieldExampleRow
                                                                key={ex.label}
                                                                example={ex}
                                                                compact
                                                                disabled={Boolean(
                                                                    customFieldsLimit && !customFieldsLimit.can_create
                                                                )}
                                                                onPick={(label, fieldType) => {
                                                                    setNewFieldSectionExpanded(true)
                                                                    setQuickFieldName(label)
                                                                    setQuickFieldDraftType(
                                                                        fieldType === 'multiselect'
                                                                            ? 'multiselect'
                                                                            : 'select'
                                                                    )
                                                                    setTimeout(() => focusQuickFieldInput(), 0)
                                                                }}
                                                            />
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                        </FieldAccordion>

                                        {offSystemRows.length > 0 ? (
                                            <FieldAccordion
                                                key={`manage-builtin-${selectedCategoryId}`}
                                                panelId={builtinPanelId}
                                                title="Built-in (off)"
                                                count={offSystemRows.length}
                                                defaultExpanded={false}
                                                subtle
                                            >
                                                <ul
                                                    className="space-y-2"
                                                    aria-label="Built-in fields not enabled for this folder"
                                                >
                                                    {offSystemRows.map((row) => (
                                                        <li key={row.key} id={`manage-field-row-${row.key}`}>
                                                            <FieldListRow
                                                                field={row.field}
                                                                isAutomated={row.isAutomated}
                                                                isEnabled={row.isEnabled}
                                                                visibilityLoading={row.visibilityLoading}
                                                                selectedCategoryId={selectedCategoryId}
                                                                selectedCategory={selectedCategory}
                                                                categoryData={fieldCategoryData[row.field.id]}
                                                                canManageVisibility={canManageVisibility}
                                                                primaryTypeKey={
                                                                    selectedCategory?.type_field?.field_key ?? null
                                                                }
                                                                onToggle={wrapToggle(row.field, selectedCategoryId)}
                                                                onConfigure={openDefinitionModal}
                                                                switchClass={switchClass}
                                                                isTenantCreated={tenantFieldIdSet.has(row.field.id)}
                                                            />
                                                        </li>
                                                    ))}
                                                </ul>
                                            </FieldAccordion>
                                        ) : hasSystemRowsInView ? (
                                            <p className="text-xs text-slate-500">All built-in fields are on.</p>
                                        ) : null}
                                    </>
                                )}
                            </div>
                        </div>
                    </>
                )}
            </div>

            <ConfirmDialog
                open={confirmDisableCoreOpen}
                onClose={() => {
                    setConfirmDisableCoreOpen(false)
                    setPendingDisable(null)
                }}
                onConfirm={() => {
                    if (!pendingDisable) return
                    const { fieldId, categoryId, fieldLabel, categoryName } = pendingDisable
                    toggleCategoryField(fieldId, categoryId, false, { fieldLabel, categoryName })
                    setPendingDisable(null)
                    setConfirmDisableCoreOpen(false)
                }}
                title="Disable field?"
                message={
                    pendingDisable
                        ? `Disable "${pendingDisable.fieldLabel}" for ${pendingDisable.categoryName}? This may affect how assets are organized.`
                        : ''
                }
                confirmText="Disable"
                cancelText="Cancel"
                variant="warning"
            />

            <MetadataFieldModal
                isOpen={modalOpen}
                onClose={() => {
                    setModalOpen(false)
                    setEditingField(null)
                    setCreateFieldPrefill(null)
                }}
                field={editingField}
                createPrefill={createFieldPrefill}
                preselectedCategoryId={selectedCategoryId}
                categories={categories}
                canManageFields={canManageFields}
                customFieldsLimit={customFieldsLimit}
                onSuccess={handleModalSuccess}
            />
        </div>
    )
}
