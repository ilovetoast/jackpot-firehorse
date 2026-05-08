import { useMemo, useState } from 'react'
import { Link } from '@inertiajs/react'
import { ChevronDownIcon, ChevronRightIcon } from '@heroicons/react/24/outline'
import { WorkbenchEmptyState } from '../../components/brand-workspace/workbenchPatterns'

const MANAGE_CATEGORIES_HREF =
    typeof route === 'function' ? route('manage.categories') : '/app/manage/categories'

function fieldTypeLabel(type) {
    const t = String(type || '').toLowerCase()
    const map = {
        select: 'Single choice',
        multiselect: 'Multiple choice',
        textarea: 'Long text',
        text: 'Text',
        number: 'Number',
        boolean: 'Yes / No',
        date: 'Date',
    }
    return map[t] || t || 'Field'
}

const VISIBILITY_CHIP_CAP = 8

function CategoryVisibilityRow({ categories, visibleCategoryIds }) {
    const visible = useMemo(() => new Set(visibleCategoryIds ?? []), [visibleCategoryIds])
    const [expanded, setExpanded] = useState(false)
    const withVis = useMemo(
        () => (categories || []).map((c) => ({ ...c, on: visible.has(c.id) })),
        [categories, visible]
    )
    const visibleCount = withVis.filter((c) => c.on).length
    const visibleOnly = useMemo(() => withVis.filter((c) => c.on), [withVis])
    if (!categories?.length) {
        return <p className="text-sm text-slate-500">No folders in this brand yet.</p>
    }
    const showList = expanded ? withVis : visibleOnly.length > 0
        ? visibleOnly.slice(0, VISIBILITY_CHIP_CAP)
        : withVis.slice(0, 4)
    const hasMore =
        !expanded &&
        (visibleOnly.length > VISIBILITY_CHIP_CAP || (visibleOnly.length === 0 && withVis.length > 4))
    return (
        <div className="mt-3">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Visible in folders</p>
            <p className="mt-1 text-xs text-slate-500">
                {visibleCount} of {categories.length} folder{categories.length === 1 ? '' : 's'} (read-only here). Change on{' '}
                <Link href={MANAGE_CATEGORIES_HREF} className="font-medium text-violet-600 hover:text-violet-500">
                    Folders & fields
                </Link>
                .
            </p>
            <ul className="mt-2 flex flex-wrap gap-1.5">
                {showList.map((cat) => (
                    <li key={cat.id}>
                        <span
                            className={`inline-flex max-w-full items-center rounded-md border px-2 py-0.5 text-xs ${
                                cat.on
                                    ? 'border-slate-200/90 bg-slate-50 text-slate-800'
                                    : 'border-slate-100/90 bg-slate-50/50 text-slate-400 line-through'
                            }`}
                        >
                            {cat.name}
                        </span>
                    </li>
                ))}
            </ul>
            {hasMore || expanded ? (
                <button
                    type="button"
                    onClick={() => setExpanded((e) => !e)}
                    className="mt-2 text-xs font-medium text-violet-600 hover:text-violet-500"
                >
                    {expanded
                        ? 'Show less'
                        : visibleOnly.length > VISIBILITY_CHIP_CAP
                          ? `Show all ${visibleOnly.length} visible folders`
                          : `Show all ${withVis.length} folders`}
                </button>
            ) : null}
        </div>
    )
}

function FieldCard({ field, categories, expanded, onToggleValues, showValuesToggle }) {
    const options = field.options ?? []
    const hasOptions = options.length > 0

    return (
        <div className="rounded-xl border border-slate-200/90 bg-white p-4 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 className="text-base font-semibold text-slate-900">{field.label || field.key}</h3>
                    <p className="mt-0.5 text-xs text-slate-500">
                        {fieldTypeLabel(field.field_type)}
                        {field.applies_to && field.applies_to !== 'all' ? ` · ${field.applies_to}` : ''}
                        {field.population_mode === 'automatic'
                            ? ' · Automated'
                            : field.population_mode === 'hybrid'
                              ? ' · Hybrid'
                              : ''}
                    </p>
                </div>
                {showValuesToggle && hasOptions && (
                    <button
                        type="button"
                        onClick={onToggleValues}
                        className="inline-flex items-center gap-1 text-sm font-medium text-violet-600 hover:text-violet-500"
                    >
                        {expanded ? (
                            <ChevronDownIcon className="h-4 w-4" />
                        ) : (
                            <ChevronRightIcon className="h-4 w-4" />
                        )}
                        {expanded ? 'Hide' : 'Show'} values ({options.length})
                    </button>
                )}
            </div>

            {showValuesToggle && hasOptions && expanded && (
                <ul className="mt-3 max-h-48 list-none space-y-1 overflow-y-auto rounded-lg border border-slate-100 bg-slate-50/80 px-3 py-2 text-sm text-slate-800">
                    {options.map((opt, i) => (
                        <li key={`${field.id}-${opt.value}-${i}`}>
                            {opt.label || opt.value}
                            {opt.label && opt.value && String(opt.label) !== String(opt.value) ? (
                                <span className="text-gray-500"> ({opt.value})</span>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}

            {showValuesToggle && !hasOptions && ['select', 'multiselect'].includes(String(field.field_type)) && (
                <p className="mt-2 text-sm text-slate-500">No predefined options configured.</p>
            )}

            <CategoryVisibilityRow categories={categories} visibleCategoryIds={field.visible_category_ids} />
        </div>
    )
}

/**
 * Read-only overview: custom fields with expandable option lists and per-folder visibility;
 * system fields in a collapsed section.
 */
export default function ManageFieldsOverview({ categories = [], customFields = [], systemFields = [] }) {
    const [valuesOpen, setValuesOpen] = useState({})
    const [systemOpen, setSystemOpen] = useState(false)

    const toggleValues = (id) => {
        setValuesOpen((prev) => ({ ...prev, [id]: !prev[id] }))
    }

    return (
        <div className="mt-6 space-y-8 sm:mt-8">
            <section>
                <h3 className="text-sm font-semibold text-slate-900">Custom fields</h3>
                {!customFields.length ? (
                    <div className="mt-4">
                        <WorkbenchEmptyState
                            title="No custom fields yet"
                            description="Add fields from a folder in Categories, then return here to see definitions and which folders they apply to."
                            action={
                                <Link
                                    href={MANAGE_CATEGORIES_HREF}
                                    className="text-sm font-medium text-violet-600 hover:text-violet-500"
                                >
                                    Open Categories →
                                </Link>
                            }
                        />
                    </div>
                ) : (
                    <div className="mt-4 space-y-4">
                        {customFields.map((field) => (
                            <FieldCard
                                key={field.id}
                                field={field}
                                categories={categories}
                                expanded={Boolean(valuesOpen[field.id])}
                                onToggleValues={() => toggleValues(field.id)}
                                showValuesToggle
                            />
                        ))}
                    </div>
                )}
            </section>

            <section className="border-t border-slate-200 pt-6">
                <button
                    type="button"
                    onClick={() => setSystemOpen((o) => !o)}
                    className="flex w-full items-center gap-2 text-left text-sm font-semibold text-slate-900"
                >
                    {systemOpen ? <ChevronDownIcon className="h-5 w-5 shrink-0" /> : (
                        <ChevronRightIcon className="h-5 w-5 shrink-0" />
                    )}
                    System &amp; default fields
                    <span className="font-normal text-slate-500">({systemFields.length})</span>
                </button>
                {!systemOpen ? (
                    <p className="mt-2 pl-7 text-sm text-slate-500">
                        Built-in metadata. Expand to browse; detailed visibility is managed per folder in Categories.
                    </p>
                ) : (
                    <div className="mt-4 space-y-4 pl-0 sm:pl-7">
                        {systemFields.map((field) => (
                            <FieldCard
                                key={field.id}
                                field={field}
                                categories={categories}
                                expanded={false}
                                onToggleValues={() => {}}
                                showValuesToggle={false}
                            />
                        ))}
                    </div>
                )}
            </section>
        </div>
    )
}
