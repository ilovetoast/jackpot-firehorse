import { useMemo, useState } from 'react'
import { Link } from '@inertiajs/react'
import { ChevronDownIcon, ChevronRightIcon } from '@heroicons/react/24/outline'

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

function CategoryVisibilityRow({ categories, visibleCategoryIds }) {
    const visible = useMemo(() => new Set(visibleCategoryIds ?? []), [visibleCategoryIds])
    if (!categories?.length) {
        return <p className="text-sm text-gray-500">No folders in this brand yet.</p>
    }
    return (
        <div className="mt-3">
            <p className="text-xs font-medium uppercase tracking-wide text-gray-500">Visible in folders</p>
            <p className="mt-1 text-xs text-gray-500">
                Checked folders show this field (not suppressed). Edit on{' '}
                <Link href={MANAGE_CATEGORIES_HREF} className="text-indigo-600 hover:text-indigo-800">
                    Categories
                </Link>
                .
            </p>
            <div className="mt-2 flex flex-wrap gap-x-4 gap-y-2">
                {categories.map((cat) => (
                    <label
                        key={cat.id}
                        className="inline-flex items-center gap-2 text-sm text-gray-700 select-none"
                    >
                        <input
                            type="checkbox"
                            className="h-4 w-4 rounded border-gray-300 text-indigo-600"
                            checked={visible.has(cat.id)}
                            disabled
                            title="View only — change on Categories"
                        />
                        <span>{cat.name}</span>
                    </label>
                ))}
            </div>
        </div>
    )
}

function FieldCard({ field, categories, expanded, onToggleValues, showValuesToggle }) {
    const options = field.options ?? []
    const hasOptions = options.length > 0

    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 className="text-base font-semibold text-gray-900">{field.label || field.key}</h3>
                    <p className="mt-0.5 text-xs text-gray-500">
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
                        className="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800"
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
                <ul className="mt-3 max-h-48 list-none space-y-1 overflow-y-auto rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-800">
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
                <p className="mt-2 text-sm text-gray-500">No predefined options configured.</p>
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
        <div className="mt-8 space-y-8">
            <section>
                <h3 className="text-sm font-semibold text-gray-900">Custom fields</h3>
                {!customFields.length ? (
                    <p className="mt-2 text-sm text-gray-600">
                        No custom fields yet for this brand. Add them from{' '}
                        <Link href={MANAGE_CATEGORIES_HREF} className="text-indigo-600 hover:text-indigo-800">
                            Categories
                        </Link>{' '}
                        when a folder is selected.
                    </p>
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

            <section className="border-t border-gray-200 pt-6">
                <button
                    type="button"
                    onClick={() => setSystemOpen((o) => !o)}
                    className="flex w-full items-center gap-2 text-left text-sm font-semibold text-gray-900"
                >
                    {systemOpen ? <ChevronDownIcon className="h-5 w-5 shrink-0" /> : (
                        <ChevronRightIcon className="h-5 w-5 shrink-0" />
                    )}
                    System &amp; default fields
                    <span className="font-normal text-gray-500">({systemFields.length})</span>
                </button>
                {!systemOpen ? (
                    <p className="mt-2 pl-7 text-sm text-gray-500">
                        Built-in metadata used across the library. Expand to see names and which folders they appear in.
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
