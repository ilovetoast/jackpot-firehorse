import { createContext, useContext, useMemo } from 'react'
import { buildBrandWorkbenchChromePackage } from '../utils/brandWorkbenchTheme'

const BrandWorkbenchChromeContext = createContext(null)

export function useBrandWorkbenchChrome() {
    return useContext(BrandWorkbenchChromeContext)
}

/**
 * Wraps Insights / Manage / Brand settings workbench content.
 * Sets CSS variables so `.brand-workbench-theme` descendant overrides map violet → brand chrome.
 *
 * Pass either `package` (precomputed) or `brand` + `company` so parents can reuse the same
 * package for inline styles (tabs) without duplicating hook/context issues.
 */
export function BrandWorkbenchChrome({ brand, company, package: pkgIn = null, className = '', children }) {
    const pkg = useMemo(() => {
        if (pkgIn) {
            return pkgIn
        }
        return buildBrandWorkbenchChromePackage(brand, company)
    }, [
        pkgIn,
        brand?.id,
        brand?.primary_color,
        brand?.accent_color,
        brand?.secondary_color,
        brand?.workspace_button_style,
        company?.id,
        company?.primary_color,
    ])

    return (
        <BrandWorkbenchChromeContext.Provider value={pkg}>
            <div className={`brand-workbench-theme min-w-0 ${className}`.trim()} style={pkg.vars}>
                {children}
            </div>
        </BrandWorkbenchChromeContext.Provider>
    )
}
