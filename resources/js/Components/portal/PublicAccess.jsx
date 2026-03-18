import PublicPageTheme from '../branding/PublicPageTheme'
import PortalGate from './PortalGate'

const VISIBILITY_OPTIONS = [
    { value: 'private', label: 'Off', desc: 'Portal is not publicly accessible' },
    { value: 'link_only', label: 'Link Only', desc: 'Accessible via direct link only' },
    { value: 'public', label: 'Public', desc: 'Discoverable on your subdomain' },
]

export default function PublicAccess({ data, setData, portalFeatures, brand, route, portalUrl }) {
    const canAccess = portalFeatures?.public_access
    const pub = data.portal_settings?.public || {}

    const updatePublic = (key, value) => {
        setData('portal_settings', {
            ...(data.portal_settings || {}),
            public: {
                ...pub,
                [key]: value,
            },
        })
    }

    return (
        <div className="space-y-8">
            <div>
                <h3 className="text-base font-semibold text-gray-900">Public Access</h3>
                <p className="mt-1 text-sm text-gray-500">
                    Control how your brand appears publicly — downloads, shared links, collections, and campaign pages.
                </p>
            </div>

            <PortalGate allowed={canAccess} planName="Premium" feature="Public Portal Access">
                <div className="space-y-6">
                    {/* Enable Toggle */}
                    <div className="flex items-center justify-between">
                        <div>
                            <label className="text-sm font-medium text-gray-700">Enable Public Portal</label>
                            <p className="text-xs text-gray-500 mt-0.5">
                                Allow external access to brand content
                            </p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={!!pub.enabled}
                            onClick={() => updatePublic('enabled', !pub.enabled)}
                            className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 ${
                                pub.enabled ? 'bg-indigo-600' : 'bg-gray-200'
                            }`}
                        >
                            <span
                                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                    pub.enabled ? 'translate-x-5' : 'translate-x-0'
                                }`}
                            />
                        </button>
                    </div>

                    {pub.enabled && (
                        <>
                            {/* Visibility */}
                            <div>
                                <label className="text-sm font-medium text-gray-700">Visibility</label>
                                <div className="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    {VISIBILITY_OPTIONS.map((opt) => (
                                        <button
                                            key={opt.value}
                                            type="button"
                                            onClick={() => updatePublic('visibility', opt.value)}
                                            className={`relative flex flex-col items-start p-3 rounded-lg border-2 transition-all text-left ${
                                                (pub.visibility || 'private') === opt.value
                                                    ? 'border-indigo-600 ring-2 ring-indigo-600/20 bg-indigo-50/30'
                                                    : 'border-gray-200 hover:border-gray-300'
                                            }`}
                                        >
                                            <span className="text-sm font-medium text-gray-900">{opt.label}</span>
                                            <span className="text-xs text-gray-500 mt-0.5">{opt.desc}</span>
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {/* Public URL Preview */}
                            {pub.visibility !== 'private' && (
                                <div className="rounded-lg bg-gray-50 p-4 border border-gray-200">
                                    <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Portal URL</p>
                                    {portalUrl ? (
                                        <div className="flex items-center gap-2">
                                            <a
                                                href={portalUrl}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-sm font-mono text-indigo-600 hover:text-indigo-700 underline decoration-indigo-300 underline-offset-2 truncate"
                                            >
                                                {portalUrl}
                                            </a>
                                            <button
                                                type="button"
                                                onClick={() => navigator.clipboard?.writeText(portalUrl)}
                                                className="flex-shrink-0 text-xs text-gray-400 hover:text-gray-600 transition-colors"
                                                title="Copy URL"
                                            >
                                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9.75a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" />
                                                </svg>
                                            </button>
                                        </div>
                                    ) : (
                                        <p className="text-sm font-mono text-gray-500">
                                            Save settings to generate portal URL
                                        </p>
                                    )}
                                    {pub.visibility === 'link_only' && portalUrl && (
                                        <p className="text-xs text-amber-600 mt-2">
                                            Link expires after 24 hours. Re-save settings to generate a fresh link.
                                        </p>
                                    )}
                                </div>
                            )}

                            {/* SEO Indexable */}
                            {pub.visibility === 'public' && (
                                <div className="flex items-center justify-between">
                                    <div>
                                        <label className="text-sm font-medium text-gray-700">Allow Search Engines</label>
                                        <p className="text-xs text-gray-500 mt-0.5">
                                            Let Google and other search engines index your public portal
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        role="switch"
                                        aria-checked={!!pub.indexable}
                                        onClick={() => updatePublic('indexable', !pub.indexable)}
                                        className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 ${
                                            pub.indexable ? 'bg-indigo-600' : 'bg-gray-200'
                                        }`}
                                    >
                                        <span
                                            className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                                pub.indexable ? 'translate-x-5' : 'translate-x-0'
                                            }`}
                                        />
                                    </button>
                                </div>
                            )}
                        </>
                    )}
                </div>
            </PortalGate>

            {/* Existing Public Page Theme (download/collection branding) */}
            <div className="pt-6 border-t border-gray-200">
                <div className="mb-4">
                    <h4 className="text-sm font-semibold text-gray-900">Public Page Theme</h4>
                    <p className="mt-1 text-xs text-gray-500">
                        Define how brand-facing pages look — downloads, shared links, collections, and campaign pages.
                    </p>
                </div>
                <PublicPageTheme
                    brand={brand}
                    data={data}
                    setData={setData}
                    route={route}
                />
            </div>
        </div>
    )
}
