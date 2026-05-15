import { useMemo, useState, useCallback } from 'react'
import { route } from 'ziggy-js'
import { EntryPreview } from './GatewayPreview'
import PortalGate from './PortalGate'

/**
 * Brand → Public Gateway → Entry experience.
 * Deferred / system-driven entry controls: docs/GATEWAY_ENTRY_CONTROLS_DEFERRED.md
 */
const STYLE_OPTIONS = [
    { value: 'cinematic', label: 'Cinematic', desc: 'Animated branded entry with progress bar' },
    { value: 'instant', label: 'Instant', desc: 'Skip animation, go straight to the app' },
]

const DESTINATION_OPTIONS = [
    { value: 'assets', label: 'Assets' },
    { value: 'guidelines', label: 'Brand Guidelines' },
    { value: 'collections', label: 'Collections' },
]

function inferTaglineSource(entry, brandDnaTagline) {
    const s = entry?.tagline_source
    if (s === 'brand' || s === 'custom' || s === 'hidden') {
        return s
    }
    if (entry?.tagline_override && String(entry.tagline_override).trim()) {
        return 'custom'
    }
    if (brandDnaTagline && String(brandDnaTagline).trim()) {
        return 'brand'
    }
    return 'hidden'
}

export default function EntryExperience({
    data,
    setData,
    portalFeatures,
    brand,
    brandDnaTagline = null,
    gatewayShowLegacyEntryControls = false,
    onSave,
}) {
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

    const [copied, setCopied] = useState(false)

    const handleCopyLink = useCallback(async () => {
        if (!gatewayLoginUrl) return
        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(gatewayLoginUrl)
            } else {
                // Fallback for HTTP / older browsers
                const ta = document.createElement('textarea')
                ta.value = gatewayLoginUrl
                ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0'
                document.body.appendChild(ta)
                ta.focus()
                ta.select()
                document.execCommand('copy')
                document.body.removeChild(ta)
            }
            setCopied(true)
            setTimeout(() => setCopied(false), 2000)
        } catch {
            // Silently ignore — clipboard not permitted in this context
        }
    }, [gatewayLoginUrl])

    const taglineSource = inferTaglineSource(entry, brandDnaTagline)

    const setTaglineSource = (next) => {
        const patch = { tagline_source: next }
        if (next === 'brand') {
            patch.tagline_override = null
        }
        if (next === 'hidden') {
            patch.tagline_override = entry.tagline_override ?? null
        }
        const newPortalSettings = {
            ...(data.portal_settings || {}),
            entry: {
                ...entry,
                ...patch,
            },
        }
        setData('portal_settings', newPortalSettings)
        onSave?.({ portal_settings: newPortalSettings })
    }

    return (
        <div className="space-y-8">
            <div>
                <h3 className="text-base font-semibold text-gray-900">Entry Experience</h3>
                <p className="mt-1 text-sm text-gray-500">
                    Share your branded login link. Gateway entry animation and landing are managed automatically for
                    your users.
                </p>
            </div>

            {gatewayLoginUrl && (
                <div
                    className="relative overflow-hidden rounded-2xl border p-6 shadow-sm"
                    style={{
                        borderColor: 'var(--jp-bs-soft-border)',
                        background: 'linear-gradient(135deg, var(--jp-bs-soft-bg) 0%, white 60%, var(--jp-bs-soft-bg) 100%)',
                    }}
                >
                    <div
                        className="absolute -right-8 -top-8 h-32 w-32 rounded-full blur-2xl"
                        style={{ background: 'var(--jp-bs-soft-bg-strong)' }}
                        aria-hidden
                    />
                    <p
                        className="text-xs font-semibold uppercase tracking-wider"
                        style={{ color: 'var(--jp-bs-primary)' }}
                    >
                        Brand login link
                    </p>
                    <p className="mt-1 text-sm text-gray-600 max-w-xl">
                        Opens the gateway login screen with this brand&apos;s theme — use in emails, invites, and docs.
                    </p>
                    <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <div className="min-w-0 flex-1 rounded-xl border border-gray-200/80 bg-white/90 px-4 py-3 shadow-inner">
                            <a
                                href={gatewayLoginUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="block truncate text-sm font-mono underline underline-offset-2"
                                style={{ color: 'var(--jp-bs-primary)', textDecorationColor: 'var(--jp-bs-soft-border)' }}
                            >
                                {gatewayLoginUrl}
                            </a>
                        </div>
                        <button
                            type="button"
                            onClick={handleCopyLink}
                            className="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 min-w-[120px]"
                            style={{
                                backgroundColor: copied ? undefined : 'var(--jp-bs-primary)',
                                color: copied ? undefined : 'var(--jp-bs-primary-contrast)',
                                '--tw-ring-color': 'var(--jp-bs-soft-border)',
                            }}
                        >
                            {copied ? (
                                <>
                                    <svg className="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor" aria-hidden>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                    <span className="text-green-700">Copied!</span>
                                </>
                            ) : (
                                <>
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" aria-hidden>
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9.75a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184"
                                        />
                                    </svg>
                                    Copy link
                                </>
                            )}
                        </button>
                    </div>
                </div>
            )}

            <PortalGate allowed={canCustomize} planName="Pro" feature="Entry Customization">
                <div className="space-y-6">
                    {gatewayShowLegacyEntryControls && (
                        <>
                            <div>
                                <label className="text-sm font-medium text-gray-700">Entry Style</label>
                                <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    {STYLE_OPTIONS.map((opt) => {
                                        const isSelected = (entry.style || 'cinematic') === opt.value
                                        return (
                                            <button
                                                key={opt.value}
                                                type="button"
                                                onClick={() => updateEntry('style', opt.value)}
                                                className="relative flex flex-col items-start rounded-lg border-2 p-4 text-left transition-all"
                                                style={isSelected ? {
                                                    borderColor: 'var(--jp-bs-primary)',
                                                    backgroundColor: 'var(--jp-bs-soft-bg)',
                                                    boxShadow: '0 0 0 2px var(--jp-bs-soft-border)',
                                                } : undefined}
                                            >
                                                <span className="text-sm font-medium text-gray-900">{opt.label}</span>
                                                <span className="mt-1 text-xs text-gray-500">{opt.desc}</span>
                                                {isSelected && (
                                                    <div className="absolute right-2 top-2">
                                                        <svg className="h-4 w-4" style={{ color: 'var(--jp-bs-primary)' }} fill="currentColor" viewBox="0 0 20 20">
                                                            <path
                                                                fillRule="evenodd"
                                                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                                clipRule="evenodd"
                                                            />
                                                        </svg>
                                                    </div>
                                                )}
                                            </button>
                                        )
                                    })}
                                </div>
                            </div>

                            <div className="flex items-center justify-between">
                                <div>
                                    <label className="text-sm font-medium text-gray-700">Auto Enter</label>
                                    <p className="mt-0.5 text-xs text-gray-500">Automatically enter when user has one brand</p>
                                </div>
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked={entry.auto_enter !== false}
                                    onClick={() => updateEntry('auto_enter', entry.auto_enter === false)}
                                    className="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-offset-2"
                                    style={{
                                        backgroundColor: entry.auto_enter !== false ? 'var(--jp-bs-primary)' : undefined,
                                        '--tw-ring-color': 'var(--jp-bs-soft-border)',
                                    }}
                                >
                                    <span
                                        className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                            entry.auto_enter !== false ? 'translate-x-5' : 'translate-x-0'
                                        }`}
                                    />
                                </button>
                            </div>

                            <div>
                                <label className="text-sm font-medium text-gray-700">Default Destination</label>
                                <p className="mt-0.5 mb-3 text-xs text-gray-500">Where users land after entering through the gateway</p>
                                <select
                                    value={entry.default_destination || 'assets'}
                                    onChange={(e) => updateEntry('default_destination', e.target.value)}
                                    className="block w-full max-w-xs rounded-lg border-gray-300 text-sm shadow-sm focus:ring-[var(--jp-bs-ring)] focus:border-[var(--jp-bs-primary)]"
                                >
                                    {DESTINATION_OPTIONS.map((opt) => (
                                        <option key={opt.value} value={opt.value}>
                                            {opt.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </>
                    )}

                    <div>
                        <label className="text-sm font-medium text-gray-700">Gateway tagline</label>
                        <p className="mt-0.5 mb-3 text-xs text-gray-500">
                            Shown under your brand name on the cinematic entry screen. Defaults to your Brand DNA tagline
                            when present.
                        </p>
                        <div className="space-y-3">
                            <label className="flex cursor-pointer items-start gap-2">
                                <input
                                    type="radio"
                                    name="tagline_source"
                                    className="mt-1 accent-[var(--jp-bs-primary)]"
                                    checked={taglineSource === 'brand'}
                                    onChange={() => setTaglineSource('brand')}
                                />
                                <span>
                                    <span className="text-sm text-gray-800">Use Brand DNA tagline</span>
                                    {brandDnaTagline ? (
                                        <span className="mt-0.5 block text-xs text-gray-500">&ldquo;{brandDnaTagline}&rdquo;</span>
                                    ) : (
                                        <span className="mt-0.5 block text-xs text-amber-700">No tagline in Brand DNA yet.</span>
                                    )}
                                </span>
                            </label>
                            <label className="flex cursor-pointer items-start gap-2">
                                <input
                                    type="radio"
                                    name="tagline_source"
                                    className="mt-1 accent-[var(--jp-bs-primary)]"
                                    checked={taglineSource === 'custom'}
                                    onChange={() => setTaglineSource('custom')}
                                />
                                <span className="text-sm text-gray-800">Custom line</span>
                            </label>
                            {taglineSource === 'custom' ? (
                                <input
                                    type="text"
                                    value={entry.tagline_override || ''}
                                    onChange={(e) => updateEntry('tagline_override', e.target.value || null, true)}
                                    onBlur={saveCurrentEntry}
                                    placeholder="e.g. Built for anglers who demand more."
                                    className="block w-full max-w-lg rounded-lg border-gray-300 text-sm shadow-sm focus:ring-[var(--jp-bs-ring)] focus:border-[var(--jp-bs-primary)]"
                                    maxLength={255}
                                />
                            ) : null}
                            <label className="flex cursor-pointer items-start gap-2">
                                <input
                                    type="radio"
                                    name="tagline_source"
                                    className="mt-1 accent-[var(--jp-bs-primary)]"
                                    checked={taglineSource === 'hidden'}
                                    onChange={() => setTaglineSource('hidden')}
                                />
                                <span className="text-sm text-gray-800">Hide tagline on gateway entry</span>
                            </label>
                        </div>
                    </div>
                </div>
            </PortalGate>

            {canCustomize && (
                <div>
                    <p className="mb-3 text-xs font-medium uppercase tracking-wider text-gray-500">Preview</p>
                    <EntryPreview brand={brand} entry={entry} brandDnaTagline={brandDnaTagline} />
                </div>
            )}
        </div>
    )
}
