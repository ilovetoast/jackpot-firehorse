import { useMemo } from 'react'
import { route } from 'ziggy-js'
import { EntryPreview } from './GatewayPreview'
import PortalGate from './PortalGate'

const STYLE_OPTIONS = [
    { value: 'cinematic', label: 'Cinematic', desc: 'Animated branded entry with progress bar' },
    { value: 'instant', label: 'Instant', desc: 'Skip animation, go straight to the app' },
]

const DESTINATION_OPTIONS = [
    { value: 'assets', label: 'Assets' },
    { value: 'guidelines', label: 'Brand Guidelines' },
    { value: 'collections', label: 'Collections' },
]

export default function EntryExperience({ data, setData, portalFeatures, brand, onSave }) {
    const canCustomize = portalFeatures?.customization
    const entry = data.portal_settings?.entry || {}

    const updateEntry = (key, value, skipSave = false) => {
        const newPortalSettings = {
            ...(data.portal_settings || {}),
            entry: {
                ...entry,
                [key]: value,
            },
        }
        setData('portal_settings', newPortalSettings)
        if (!skipSave) {
            onSave?.({ portal_settings: newPortalSettings })
        }
    }

    const saveCurrentEntry = () => {
        onSave?.({ portal_settings: data.portal_settings })
    }

    const gatewayLoginUrl = useMemo(() => {
        if (!brand?.slug) return ''
        const base = route('gateway')
        const qs = new URLSearchParams({ mode: 'login', brand: brand.slug })
        return `${base}?${qs.toString()}`
    }, [brand?.slug])

    return (
        <div className="space-y-8">
            <div>
                <h3 className="text-base font-semibold text-gray-900">Entry Experience</h3>
                <p className="mt-1 text-sm text-gray-500">
                    Control how users enter your brand when they arrive at the gateway.
                </p>
            </div>

            {gatewayLoginUrl && (
                <div className="rounded-lg bg-gray-50 p-4 border border-gray-200">
                    <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Brand login link</p>
                    <p className="text-xs text-gray-600 mb-3">
                        Share this URL to open the login screen with this brand&apos;s gateway (theme and entry experience below).
                    </p>
                    <div className="flex items-center gap-2 min-w-0">
                        <a
                            href={gatewayLoginUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-sm font-mono text-indigo-600 hover:text-indigo-700 underline decoration-indigo-300 underline-offset-2 truncate"
                        >
                            {gatewayLoginUrl}
                        </a>
                        <button
                            type="button"
                            onClick={() => navigator.clipboard?.writeText(gatewayLoginUrl)}
                            className="flex-shrink-0 text-xs text-gray-400 hover:text-gray-600 transition-colors"
                            title="Copy URL"
                        >
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9.75a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" />
                            </svg>
                        </button>
                    </div>
                </div>
            )}

            <PortalGate allowed={canCustomize} planName="Pro" feature="Entry Customization">
                <div className="space-y-6">
                    <div>
                        <label className="text-sm font-medium text-gray-700">Entry Style</label>
                        <div className="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            {STYLE_OPTIONS.map((opt) => (
                                <button
                                    key={opt.value}
                                    type="button"
                                    onClick={() => updateEntry('style', opt.value)}
                                    className={`relative flex flex-col items-start p-4 rounded-lg border-2 transition-all text-left ${
                                        (entry.style || 'cinematic') === opt.value
                                            ? 'border-indigo-600 ring-2 ring-indigo-600/20 bg-indigo-50/30'
                                            : 'border-gray-200 hover:border-gray-300'
                                    }`}
                                >
                                    <span className="text-sm font-medium text-gray-900">{opt.label}</span>
                                    <span className="text-xs text-gray-500 mt-1">{opt.desc}</span>
                                    {(entry.style || 'cinematic') === opt.value && (
                                        <div className="absolute top-2 right-2">
                                            <svg className="h-4 w-4 text-indigo-600" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                            </svg>
                                        </div>
                                    )}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Auto Enter */}
                    <div className="flex items-center justify-between">
                        <div>
                            <label className="text-sm font-medium text-gray-700">Auto Enter</label>
                            <p className="text-xs text-gray-500 mt-0.5">
                                Automatically enter when user has one brand
                            </p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={entry.auto_enter !== false}
                            onClick={() => updateEntry('auto_enter', entry.auto_enter === false)}
                            className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 ${
                                entry.auto_enter !== false ? 'bg-indigo-600' : 'bg-gray-200'
                            }`}
                        >
                            <span
                                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                    entry.auto_enter !== false ? 'translate-x-5' : 'translate-x-0'
                                }`}
                            />
                        </button>
                    </div>

                    {/* Default Destination */}
                    <div>
                        <label className="text-sm font-medium text-gray-700">Default Destination</label>
                        <p className="text-xs text-gray-500 mt-0.5 mb-3">
                            Where users land after entering through the gateway
                        </p>
                        <select
                            value={entry.default_destination || 'assets'}
                            onChange={(e) => updateEntry('default_destination', e.target.value)}
                            className="block w-full max-w-xs rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                        >
                            {DESTINATION_OPTIONS.map((opt) => (
                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                            ))}
                        </select>
                    </div>

                    {/* Tagline Override */}
                    <div>
                        <label className="text-sm font-medium text-gray-700">Portal Tagline</label>
                        <p className="text-xs text-gray-500 mt-0.5 mb-3">
                            Override the tagline shown on the gateway entry screen
                        </p>
                        <input
                            type="text"
                            value={entry.tagline_override || ''}
                            onChange={(e) => updateEntry('tagline_override', e.target.value || null, true)}
                            onBlur={saveCurrentEntry}
                            placeholder="e.g. Built for anglers who demand more."
                            className="block w-full max-w-lg rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                            maxLength={255}
                        />
                    </div>
                </div>
            </PortalGate>

            {/* Live Preview — uses same rendering as the real gateway */}
            {canCustomize && (
                <div>
                    <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-3">Preview</p>
                    <EntryPreview brand={brand} entry={entry} />
                </div>
            )}
        </div>
    )
}
