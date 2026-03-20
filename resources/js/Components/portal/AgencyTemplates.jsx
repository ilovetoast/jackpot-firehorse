import PortalGate from './PortalGate'

const LOCKABLE_FIELDS = [
    { key: 'entry.style', label: 'Entry Style' },
    { key: 'entry.default_destination', label: 'Default Destination' },
    { key: 'public.visibility', label: 'Public Visibility' },
    { key: 'invite.headline', label: 'Invite Headline' },
    { key: 'invite.background_style', label: 'Invite Background' },
]

export default function AgencyTemplates({ data, setData, portalFeatures, onSave }) {
    const canAccess = portalFeatures?.agency_templates
    const agency = data.portal_settings?.agency_template || {}
    const lockedFields = agency.locked_fields || []

    const updateAgency = (key, value) => {
        const newPortalSettings = {
            ...(data.portal_settings || {}),
            agency_template: {
                ...agency,
                [key]: value,
            },
        }
        setData('portal_settings', newPortalSettings)
        onSave?.({ portal_settings: newPortalSettings })
    }

    const toggleLockedField = (fieldKey) => {
        const current = new Set(lockedFields)
        if (current.has(fieldKey)) {
            current.delete(fieldKey)
        } else {
            current.add(fieldKey)
        }
        updateAgency('locked_fields', [...current])
    }

    return (
        <div className="space-y-8">
            <div>
                <h3 className="text-base font-semibold text-gray-900">Agency Templates</h3>
                <p className="mt-1 text-sm text-gray-500">
                    Apply a managed template to enforce consistency across client brands.
                    Locked fields cannot be changed by brand editors.
                </p>
            </div>

            <PortalGate allowed={canAccess} planName="Enterprise" feature="Agency Templates">
                <div className="space-y-6">
                    {/* Enable Toggle */}
                    <div className="flex items-center justify-between">
                        <div>
                            <label className="text-sm font-medium text-gray-700">Use Agency Template</label>
                            <p className="text-xs text-gray-500 mt-0.5">
                                When enabled, selected fields are locked to the template values
                            </p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={!!agency.enabled}
                            onClick={() => updateAgency('enabled', !agency.enabled)}
                            className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 ${
                                agency.enabled ? 'bg-indigo-600' : 'bg-gray-200'
                            }`}
                        >
                            <span
                                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                    agency.enabled ? 'translate-x-5' : 'translate-x-0'
                                }`}
                            />
                        </button>
                    </div>

                    {agency.enabled && (
                        <>
                            {/* Template ID */}
                            <div>
                                <label className="text-sm font-medium text-gray-700">Template ID</label>
                                <p className="text-xs text-gray-500 mt-0.5 mb-3">
                                    Reference identifier for this template
                                </p>
                                <input
                                    type="text"
                                    value={agency.template_id || ''}
                                    onChange={(e) => updateAgency('template_id', e.target.value || null)}
                                    placeholder="e.g. agency-standard-v1"
                                    className="block w-full max-w-xs rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm font-mono"
                                    maxLength={100}
                                />
                            </div>

                            {/* Locked Fields */}
                            <div>
                                <label className="text-sm font-medium text-gray-700">Locked Fields</label>
                                <p className="text-xs text-gray-500 mt-0.5 mb-3">
                                    Select which portal settings are locked and cannot be changed by brand editors
                                </p>
                                <div className="space-y-2">
                                    {LOCKABLE_FIELDS.map((field) => {
                                        const isLocked = lockedFields.includes(field.key)
                                        return (
                                            <label
                                                key={field.key}
                                                className={`flex items-center gap-3 p-3 rounded-lg border transition-colors cursor-pointer ${
                                                    isLocked
                                                        ? 'border-indigo-200 bg-indigo-50/50'
                                                        : 'border-gray-200 hover:border-gray-300'
                                                }`}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={isLocked}
                                                    onChange={() => toggleLockedField(field.key)}
                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                                />
                                                <div className="flex items-center gap-2 flex-1">
                                                    <span className="text-sm text-gray-700">{field.label}</span>
                                                    {isLocked && (
                                                        <svg className="h-3.5 w-3.5 text-indigo-500" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor">
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                                        </svg>
                                                    )}
                                                </div>
                                            </label>
                                        )
                                    })}
                                </div>
                            </div>

                            {/* Status Summary */}
                            <div className="rounded-lg bg-gray-50 p-4 border border-gray-200">
                                <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Template Status</p>
                                <p className="text-sm text-gray-600">
                                    {lockedFields.length} of {LOCKABLE_FIELDS.length} fields locked
                                    {agency.template_id && (
                                        <span className="ml-2 text-xs font-mono text-gray-400">({agency.template_id})</span>
                                    )}
                                </p>
                            </div>
                        </>
                    )}
                </div>
            </PortalGate>
        </div>
    )
}
