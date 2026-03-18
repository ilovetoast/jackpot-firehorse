import PortalGate from './PortalGate'

export default function SharingLinks({ data, setData, portalFeatures }) {
    const canAccess = portalFeatures?.sharing
    const sharing = data.portal_settings?.sharing || {}

    const updateSharing = (key, value) => {
        setData('portal_settings', {
            ...(data.portal_settings || {}),
            sharing: {
                ...sharing,
                [key]: value,
            },
        })
    }

    return (
        <div className="space-y-8">
            <div>
                <h3 className="text-base font-semibold text-gray-900">Sharing & External Access</h3>
                <p className="mt-1 text-sm text-gray-500">
                    Control how content can be shared externally from this brand.
                </p>
            </div>

            <PortalGate allowed={canAccess} planName="Premium" feature="Advanced Sharing">
                <div className="space-y-6">
                    {/* External Collections */}
                    <div className="flex items-center justify-between">
                        <div>
                            <label className="text-sm font-medium text-gray-700">Share Collections Externally</label>
                            <p className="text-xs text-gray-500 mt-0.5">
                                Allow collections to be shared with people outside the workspace
                            </p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={!!sharing.external_collections}
                            onClick={() => updateSharing('external_collections', !sharing.external_collections)}
                            className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 ${
                                sharing.external_collections ? 'bg-indigo-600' : 'bg-gray-200'
                            }`}
                        >
                            <span
                                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                    sharing.external_collections ? 'translate-x-5' : 'translate-x-0'
                                }`}
                            />
                        </button>
                    </div>

                    {/* Expiring Links */}
                    <div className="flex items-center justify-between">
                        <div>
                            <label className="text-sm font-medium text-gray-700">Expiring Links</label>
                            <p className="text-xs text-gray-500 mt-0.5">
                                Generate time-limited access links for external viewers
                            </p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={!!sharing.expiring_links}
                            onClick={() => updateSharing('expiring_links', !sharing.expiring_links)}
                            className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 ${
                                sharing.expiring_links ? 'bg-indigo-600' : 'bg-gray-200'
                            }`}
                        >
                            <span
                                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                    sharing.expiring_links ? 'translate-x-5' : 'translate-x-0'
                                }`}
                            />
                        </button>
                    </div>

                    {/* Watermark / Branding */}
                    <div className="flex items-center justify-between">
                        <div>
                            <label className="text-sm font-medium text-gray-700">
                                Watermark & Branding
                                <span className="ml-2 inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-500">Coming soon</span>
                            </label>
                            <p className="text-xs text-gray-500 mt-0.5">
                                Apply branded watermarks to shared and downloaded assets
                            </p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={!!sharing.watermark_branding}
                            onClick={() => updateSharing('watermark_branding', !sharing.watermark_branding)}
                            className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 ${
                                sharing.watermark_branding ? 'bg-indigo-600' : 'bg-gray-200'
                            }`}
                        >
                            <span
                                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                    sharing.watermark_branding ? 'translate-x-5' : 'translate-x-0'
                                }`}
                            />
                        </button>
                    </div>

                    {/* Download Permissions Info */}
                    <div className="rounded-lg bg-gray-50 p-4 border border-gray-200">
                        <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Download Permissions</p>
                        <p className="text-sm text-gray-600">
                            Fine-grained download permissions are configured per download link. See Downloads for more control.
                        </p>
                    </div>
                </div>
            </PortalGate>
        </div>
    )
}
