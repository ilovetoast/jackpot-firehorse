/**
 * Company vs brand scope callout. Keeps account-level changes distinct from brand settings.
 *
 * - **Company**: light Jackpot (violet) tint so company-wide pages stay visually grouped.
 * - **Brand**: neutral white/gray only — avoids stacking brand accent on top of brand accent
 *   (e.g. orange wash + orange tabs), which reads noisy.
 */
import { PLACEMENT_SURFACES } from '../placement/surfaces'

const BRAND_SCOPE_PANEL = 'rounded-lg border border-gray-200 bg-white shadow-sm'

export default function ScopeBanner({ scope, name, className = '' }) {
    const isCompany = scope === 'company'
    const tenant = PLACEMENT_SURFACES.tenant

    const panelClass = isCompany ? tenant.panel : BRAND_SCOPE_PANEL
    const bodyClass = isCompany ? tenant.body : 'text-gray-600'
    const labelClass = isCompany ? tenant.muted : 'text-gray-500'
    const dividerClass = isCompany ? `${tenant.muted} opacity-40` : 'text-gray-300'

    return (
        <div className={`${panelClass} px-4 py-3.5 text-sm ${className}`.trim()} role="note">
            <p className={`flex flex-wrap items-baseline gap-x-2 gap-y-1 ${bodyClass}`}>
                <span className={`shrink-0 text-[10px] font-semibold uppercase tracking-wider ${labelClass}`}>
                    Scope: {isCompany ? 'Company' : 'Brand'}
                </span>
                <span className={`hidden min-[380px]:inline ${dividerClass}`} aria-hidden>
                    |
                </span>
                <span>
                    {isCompany ? (
                        <>Changes here affect all brands in {name}.</>
                    ) : (
                        <>Changes here affect only {name}.</>
                    )}
                </span>
            </p>
        </div>
    )
}
