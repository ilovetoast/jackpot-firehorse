import { useState, useEffect, useCallback } from 'react'
import { createPortal } from 'react-dom'
import { router } from '@inertiajs/react'
import { XMarkIcon } from '@heroicons/react/24/outline'

const METADATA_FIELD_STORE_URL =
    typeof route === 'function' ? route('admin.metadata.fields.store') : '/app/admin/metadata/fields'

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

/**
 * Create system metadata field — layout aligned with tenant MetadataFieldModal (header bar, scroll body, portal).
 */
export default function SystemMetadataFieldModal({ isOpen, onClose, latestSystemTemplates = [] }) {
    const [addFieldForm, setAddFieldForm] = useState(() => ({ ...ADD_FIELD_INITIAL }))
    const [addOptions, setAddOptions] = useState([{ value: '', label: '' }])
    const [selectedTemplateIds, setSelectedTemplateIds] = useState(() => new Set())
    const [addFieldErrors, setAddFieldErrors] = useState({})
    const [addFieldProcessing, setAddFieldProcessing] = useState(false)

    const reset = useCallback(() => {
        setAddFieldForm({ ...ADD_FIELD_INITIAL })
        setAddOptions([{ value: '', label: '' }])
        setSelectedTemplateIds(new Set())
        setAddFieldErrors({})
    }, [])

    useEffect(() => {
        if (isOpen) reset()
    }, [isOpen, reset])

    const toggleTemplateSelected = useCallback((id) => {
        setSelectedTemplateIds((prev) => {
            const next = new Set(prev)
            if (next.has(id)) next.delete(id)
            else next.add(id)
            return next
        })
    }, [])

    const handleSubmit = useCallback(
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
                onSuccess: () => {
                    reset()
                    onClose()
                },
                onError: (errs) => setAddFieldErrors(errs || {}),
            })
        },
        [addFieldForm, addOptions, selectedTemplateIds, onClose, reset]
    )

    if (!isOpen || typeof window === 'undefined' || !document.body) {
        return null
    }

    return createPortal(
        <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="system-metadata-modal-title" role="dialog" aria-modal="true">
            <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div className="fixed inset-0 bg-gray-500/75 transition-opacity" onClick={onClose} aria-hidden="true" />

                <div className="relative transform overflow-hidden rounded-xl bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl">
                    <div className="flex items-center justify-between border-b border-gray-200 px-4 py-3 sm:px-5">
                        <div className="min-w-0">
                            <h3 id="system-metadata-modal-title" className="text-base font-semibold text-gray-900 truncate">
                                Create system metadata field
                            </h3>
                            <p className="mt-0.5 text-xs text-gray-500">
                                Defines a global field. Use System categories to attach it to default bundles.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            className="flex-shrink-0 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                        >
                            <span className="sr-only">Close</span>
                            <XMarkIcon className="h-5 w-5" />
                        </button>
                    </div>

                    <form onSubmit={handleSubmit} className="max-h-[calc(100vh-10rem)] overflow-y-auto px-4 py-4 sm:px-5 sm:py-5">
                        <div className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label htmlFor="smf-label" className="mb-1 block text-xs font-medium text-gray-700">
                                        Display name <span className="text-red-500">*</span>
                                    </label>
                                    <input
                                        id="smf-label"
                                        value={addFieldForm.system_label}
                                        onChange={(e) => setAddFieldForm((p) => ({ ...p, system_label: e.target.value }))}
                                        className="block w-full rounded-md border border-gray-300 px-2.5 py-1.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        required
                                        autoComplete="off"
                                    />
                                    {addFieldErrors.system_label && (
                                        <p className="mt-1 text-xs text-red-600">{addFieldErrors.system_label}</p>
                                    )}
                                </div>
                                <div>
                                    <label htmlFor="smf-key" className="mb-1 block text-xs font-medium text-gray-700">
                                        Key (snake_case) <span className="text-red-500">*</span>
                                    </label>
                                    <input
                                        id="smf-key"
                                        value={addFieldForm.key}
                                        onChange={(e) => setAddFieldForm((p) => ({ ...p, key: e.target.value }))}
                                        className="block w-full rounded-md border border-gray-300 px-2.5 py-1.5 font-mono text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        required
                                        autoComplete="off"
                                    />
                                    {addFieldErrors.key && <p className="mt-1 text-xs text-red-600">{addFieldErrors.key}</p>}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label htmlFor="smf-type" className="mb-1 block text-xs font-medium text-gray-700">
                                        Type
                                    </label>
                                    <select
                                        id="smf-type"
                                        value={addFieldForm.type}
                                        onChange={(e) => setAddFieldForm((p) => ({ ...p, type: e.target.value }))}
                                        className="block w-full rounded-md border border-gray-300 px-2.5 py-1.5 text-sm"
                                    >
                                        {['text', 'textarea', 'number', 'boolean', 'date', 'select', 'multiselect'].map((t) => (
                                            <option key={t} value={t}>
                                                {t}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label htmlFor="smf-applies" className="mb-1 block text-xs font-medium text-gray-700">
                                        Applies to
                                    </label>
                                    <select
                                        id="smf-applies"
                                        value={addFieldForm.applies_to}
                                        onChange={(e) => setAddFieldForm((p) => ({ ...p, applies_to: e.target.value }))}
                                        className="block w-full rounded-md border border-gray-300 px-2.5 py-1.5 text-sm"
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
                                <label htmlFor="smf-pop" className="mb-1 block text-xs font-medium text-gray-700">
                                    Population
                                </label>
                                <select
                                    id="smf-pop"
                                    value={addFieldForm.population_mode}
                                    onChange={(e) => setAddFieldForm((p) => ({ ...p, population_mode: e.target.value }))}
                                    className="block w-full rounded-md border border-gray-300 px-2.5 py-1.5 text-sm sm:max-w-xs"
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
                                                className="flex-1 rounded-md border border-gray-300 px-2 py-1.5 font-mono text-sm"
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
                                        onChange={(e) => setAddFieldForm((p) => ({ ...p, show_on_upload: e.target.checked }))}
                                        className="rounded border-gray-300"
                                    />
                                    Upload
                                </label>
                                <label className="inline-flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={addFieldForm.show_on_edit}
                                        onChange={(e) => setAddFieldForm((p) => ({ ...p, show_on_edit: e.target.checked }))}
                                        className="rounded border-gray-300"
                                    />
                                    Edit
                                </label>
                                <label className="inline-flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={addFieldForm.show_in_filters}
                                        onChange={(e) => setAddFieldForm((p) => ({ ...p, show_in_filters: e.target.checked }))}
                                        className="rounded border-gray-300"
                                    />
                                    Filters
                                </label>
                                <label className="inline-flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={addFieldForm.readonly}
                                        onChange={(e) => setAddFieldForm((p) => ({ ...p, readonly: e.target.checked }))}
                                        className="rounded border-gray-300"
                                    />
                                    Read-only
                                </label>
                            </div>

                            {latestSystemTemplates.length > 0 && (
                                <div>
                                    <p className="text-xs font-medium text-gray-700">Add to template bundles (optional)</p>
                                    <p className="mt-0.5 text-xs text-gray-500">
                                        Creates default bundle rows; adjust visibility later in System categories.
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
                                                    {t.name} <span className="text-gray-400">({t.asset_type})</span>
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>

                        <div className="mt-6 flex justify-end gap-2 border-t border-gray-100 pt-4">
                            <button
                                type="button"
                                onClick={onClose}
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
        </div>,
        document.body
    )
}
