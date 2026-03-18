import { InvitePreview } from './GatewayPreview'
import PortalGate from './PortalGate'

export default function InviteExperience({ data, setData, portalFeatures, brand }) {
    const canCustomize = portalFeatures?.customization
    const invite = data.portal_settings?.invite || {}
    const primary = brand?.primary_color || '#6366f1'

    const updateInvite = (key, value) => {
        setData('portal_settings', {
            ...(data.portal_settings || {}),
            invite: {
                ...invite,
                [key]: value,
            },
        })
    }

    return (
        <div className="space-y-8">
            <div>
                <h3 className="text-base font-semibold text-gray-900">Invite Experience</h3>
                <p className="mt-1 text-sm text-gray-500">
                    Customize the experience when someone receives an invitation to your brand.
                </p>
            </div>

            <PortalGate allowed={canCustomize} planName="Pro" feature="Invite Customization">
                <div className="space-y-6">
                    {/* Headline */}
                    <div>
                        <label className="text-sm font-medium text-gray-700">Invite Headline</label>
                        <p className="text-xs text-gray-500 mt-0.5 mb-3">
                            Custom headline for the invite acceptance page
                        </p>
                        <input
                            type="text"
                            value={invite.headline || ''}
                            onChange={(e) => updateInvite('headline', e.target.value || null)}
                            placeholder={`Welcome to ${brand?.name || 'Brand'}`}
                            className="block w-full max-w-lg rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                            maxLength={255}
                        />
                    </div>

                    {/* Subtext */}
                    <div>
                        <label className="text-sm font-medium text-gray-700">Invite Subtext</label>
                        <p className="text-xs text-gray-500 mt-0.5 mb-3">
                            Supporting message below the headline
                        </p>
                        <textarea
                            value={invite.subtext || ''}
                            onChange={(e) => updateInvite('subtext', e.target.value || null)}
                            placeholder="Built for anglers who demand more."
                            rows={2}
                            className="block w-full max-w-lg rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                            maxLength={500}
                        />
                    </div>

                    {/* CTA Label */}
                    <div>
                        <label className="text-sm font-medium text-gray-700">CTA Button Label</label>
                        <p className="text-xs text-gray-500 mt-0.5 mb-3">
                            Custom label for the accept button
                        </p>
                        <input
                            type="text"
                            value={invite.cta_label || ''}
                            onChange={(e) => updateInvite('cta_label', e.target.value || null)}
                            placeholder="Accept & Enter"
                            className="block w-full max-w-xs rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                            maxLength={100}
                        />
                    </div>
                </div>
            </PortalGate>

            {/* Live Preview — uses same rendering as the real invite page */}
            {canCustomize && (
                <div>
                    <p className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-3">Preview</p>
                    <InvitePreview brand={brand} invite={invite} />
                </div>
            )}
        </div>
    )
}
