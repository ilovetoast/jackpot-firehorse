import { useEffect } from 'react'

/**
 * Determines if the current page is a settings/configuration page
 * Settings pages should use default Jackpot colors, not brand colors
 */
const isSettingsPage = (url) => {
    // Remove query string and hash for matching
    const path = url.split('?')[0].split('#')[0]
    
    // Exact matches for settings pages
    if (path === '/app/companies/settings' || 
        path === '/app/profile' || 
        path === '/app/billing' || 
        path === '/app/categories' ||
        path === '/app/brands') {
        return true
    }
    
    // Pattern matches for settings pages
    if (/^\/app\/admin/.test(path) || // All admin pages
        /^\/app\/brands\/\d+\/edit$/.test(path)) { // Brand edit page (settings for brand)
        return true
    }
    
    return false
}

/**
 * BrandThemeProvider component that sets CSS variables based on active brand
 * and whether we're on a settings page or content page
 * 
 * Note: This component receives Inertia props directly since it wraps the App
 * and cannot use usePage() hook (which requires being inside Inertia context)
 */
export default function BrandThemeProvider({ children, initialPage }) {
    // Extract auth from initial page props
    // Inertia structure: { initialPage: { component, props: { auth, ... }, url }, ... }
    const auth = initialPage?.props?.auth
    const activeBrand = auth?.activeBrand

    // Helper function to convert hex to RGB
    const hexToRgb = (hex) => {
        const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex)
        return result ? {
            r: parseInt(result[1], 16),
            g: parseInt(result[2], 16),
            b: parseInt(result[3], 16)
        } : null
    }

    // Function to update CSS variables for brand colors
    const updateBrandColors = (brand, currentUrl) => {
        const root = document.documentElement
        const defaultPrimary = '#6366f1'
        const defaultSecondary = '#8b5cf6'
        const defaultAccent = '#ec4899'
        const isSettings = isSettingsPage(currentUrl)
        
        let primaryColor, secondaryColor, accentColor
        
        if (isSettings) {
            // Settings pages use default Jackpot colors
            primaryColor = defaultPrimary
            secondaryColor = defaultSecondary
            accentColor = defaultAccent
        } else {
            // Content pages use brand colors
            primaryColor = brand?.primary_color || defaultPrimary
            secondaryColor = brand?.secondary_color || defaultSecondary
            accentColor = brand?.accent_color || defaultAccent
        }
        
        root.style.setProperty('--primary', primaryColor)
        root.style.setProperty('--secondary', secondaryColor)
        root.style.setProperty('--accent', accentColor)
        
        // Also set RGB values for opacity-based colors
        const primaryRgb = hexToRgb(primaryColor)
        if (primaryRgb) {
            root.style.setProperty('--primary-rgb', `${primaryRgb.r}, ${primaryRgb.g}, ${primaryRgb.b}`)
        }
    }

    // Initial setup
    useEffect(() => {
        const currentUrl = window.location.pathname
        updateBrandColors(activeBrand, currentUrl)
        
        // Cleanup: reset to default on unmount
        return () => {
            const root = document.documentElement
            root.style.setProperty('--primary', '#6366f1')
            root.style.setProperty('--secondary', '#8b5cf6')
            root.style.setProperty('--accent', '#ec4899')
            root.style.setProperty('--primary-rgb', '99, 102, 241')
        }
    }, [activeBrand])

    // Listen for Inertia page updates
    useEffect(() => {
        const handlePageUpdate = () => {
            // Get current URL and try to get auth from Inertia's page object
            const currentUrl = window.location.pathname
            let brand = activeBrand
            
            // Try to get updated auth from Inertia's global page object
            if (window.$inertia?.page?.props?.auth?.activeBrand) {
                brand = window.$inertia.page.props.auth.activeBrand
            }
            
            updateBrandColors(brand, currentUrl)
        }

        // Listen for Inertia navigation events
        // Inertia fires these events: 'inertia:start', 'inertia:progress', 'inertia:finish'
        document.addEventListener('inertia:finish', handlePageUpdate)
        document.addEventListener('inertia:start', handlePageUpdate)
        
        // Also listen to popstate for browser back/forward
        const handlePopState = () => {
            setTimeout(handlePageUpdate, 50)
        }
        window.addEventListener('popstate', handlePopState)
        
        return () => {
            document.removeEventListener('inertia:finish', handlePageUpdate)
            document.removeEventListener('inertia:start', handlePageUpdate)
            window.removeEventListener('popstate', handlePopState)
        }
    }, [activeBrand])

    return children
}
